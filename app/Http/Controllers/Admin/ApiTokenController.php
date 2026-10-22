<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ApiTokenRequest;
use App\Models\ApiToken;
use App\Models\User;
use App\Services\ActivityRecorder;
use App\Services\Api\ApiDocumentation;
use App\Services\Authorization\PermissionRegistry;
use App\Services\Settings\SettingsTabs;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * API jetonlarının yönetimi.
 *
 * Jeton bir KİMLİK BİLGİSİDİR: sahibi adına konuşur ve onun yetkilerini
 * taşır. Bu yüzden jeton çıkarmak YALNIZCA yöneticidedir — başkası adına
 * jeton çıkarmak, o kişinin yetkisini kalıcı bir anahtara dönüştürmektir.
 *
 * Sır YALNIZCA bir kez gösterilir. Kaybolursa geri gösterilemez; yenisi
 * üretilir (rotate) ve eskisi anında geçersiz olur.
 */
class ApiTokenController extends Controller
{
    public function __construct(
        private SettingsTabs $tabs,
        private PermissionRegistry $registry,
        private ActivityRecorder $activity,
    ) {}

    public function index(): View
    {
        return view('settings.api', [
            'sections' => $this->tabs->all('api'),
            'tokens' => ApiToken::query()->with(['user', 'creator'])->latest('id')->get(),
            'users' => User::query()->where('aktif', true)->orderBy('name')->pluck('name', 'id')->all(),
            'resources' => $this->resources(),
            'actionLabels' => $this->registry->actions(),
            'defaultLifetime' => (int) config('api.default_lifetime_days'),
        ]);
    }

    /**
     * API belgesi.
     *
     * Uç listesi ROTALARDAN üretilir; elle yazılmış ikinci bir liste yoktur
     * (bkz. ApiDocumentation). Belgesiz bir uç eklendiğinde test kırılır.
     */
    public function documentation(ApiDocumentation $docs): View
    {
        return view('settings.api-docs', [
            'sections' => $this->tabs->all('api'),
            'endpoints' => $docs->endpoints(),
            'baseUrl' => url('/api/v1'),
            'rateLimit' => (int) config('api.rate_limit'),
            'prefix' => (string) config('api.prefix'),
        ]);
    }

    public function show(ApiToken $apiToken): View
    {
        return view('settings.api-token', [
            'sections' => $this->tabs->all('api'),
            'token' => $apiToken->load(['user', 'creator']),
            'resources' => $this->resources(),
            'actionLabels' => $this->registry->actions(),
            'abilities' => $apiToken->abilityMap(),
        ]);
    }

    public function store(ApiTokenRequest $request): RedirectResponse
    {
        $owner = User::query()->findOrFail($request->integer('user'));
        $secret = ApiToken::mint();

        $token = ApiToken::query()->create([
            'name' => $request->string('name')->toString(),
            'user_id' => $owner->id,
            'created_by' => $request->user()?->id,
            'token_hash' => $secret['hash'],
            'prefix' => $secret['prefix'],
            'abilities' => $request->abilities($this->registry),
            'allowed_ips' => $request->ipList(),
            'expires_at' => $request->expiresAt(),
        ]);

        $this->activity->kaydet(
            'api_jeton_ekle',
            "API jetonu çıkarıldı: {$token->name} · sahibi {$owner->email}",
            $token,
        );

        return redirect()
            ->route('admin.api.show', $token)
            // Sır YALNIZCA burada görünür; oturumda tek seferlik durur.
            ->with('token_plain', $secret['plain'])
            ->with('status', 'Jeton çıkarıldı. Sır yalnızca şimdi gösterilir.');
    }

    public function update(ApiTokenRequest $request, ApiToken $apiToken): RedirectResponse
    {
        $apiToken->update([
            'name' => $request->string('name')->toString(),
            'abilities' => $request->abilities($this->registry),
            'allowed_ips' => $request->ipList(),
            'expires_at' => $request->expiresAt(),
        ]);

        $this->activity->kaydet(
            'api_jeton_guncelle',
            "API jetonu güncellendi: {$apiToken->name}",
            $apiToken,
        );

        return back()->with('status', "{$apiToken->name} kaydedildi.");
    }

    /**
     * Yeni bir sır üretir; eskisi anında geçersiz olur.
     */
    public function rotate(ApiToken $apiToken): RedirectResponse
    {
        /* İptal edilmiş jeton yenilenmez: yeni sır, yeniden etkinleştirmeyle aynı şey olurdu. */
        if ($apiToken->revoked_at !== null) {
            return back()->withErrors(['token' => 'İptal edilmiş jetonun sırrı yenilenemez; yeni bir jeton oluşturun.']);
        }

        $plain = $apiToken->rotate();

        $this->activity->kaydet(
            'api_jeton_sir',
            "API jetonunun sırrı değiştirildi: {$apiToken->name}",
            $apiToken,
        );

        return back()
            ->with('token_plain', $plain)
            ->with('status', 'Yeni sır üretildi. Eski jeton bu andan itibaren geçersiz.');
    }

    /**
     * İptal eder ama SİLMEZ: kimin hangi jetonla ne yaptığı kayıtta durur.
     */
    public function revoke(ApiToken $apiToken): RedirectResponse
    {
        $apiToken->revoke();

        $this->activity->kaydet(
            'api_jeton_iptal',
            "API jetonu iptal edildi: {$apiToken->name}",
            $apiToken,
        );

        return back()->with('status', "{$apiToken->name} iptal edildi.");
    }

    public function destroy(ApiToken $apiToken): RedirectResponse
    {
        $ad = $apiToken->name;
        $apiToken->delete();

        $this->activity->kaydet('api_jeton_sil', "API jetonu silindi: {$ad}");

        return redirect()->route('admin.api.index')->with('status', "{$ad} silindi.");
    }

    /**
     * @return list<array{key: string, label: string, actions: list<string>}>
     */
    private function resources(): array
    {
        return array_map(fn (string $key): array => [
            'key' => $key,
            'label' => $this->registry->label($key),
            'actions' => $this->registry->actionsFor($key),
        ], $this->registry->resourceKeys());
    }
}
