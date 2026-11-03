<?php

declare(strict_types=1);

namespace App\ConsoleModule\Http\Requests;

use App\ConsoleModule\Contracts\ConfigurationSource;
use App\ConsoleModule\Contracts\ImageSource;
use App\ConsoleModule\Junos\YedekDosyasi;
use App\ConsoleModule\Models\ConsoleJob;
use App\ConsoleModule\Models\ConsolePc;
use App\ConsoleModule\Services\AgentJob;
use App\ConsoleModule\Services\Ozellestirme;
use App\Rules\JunosGoruntusu;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Yeni iş. Bir PC'de aynı anda TEK açık iş olur: kablo bir tane.
 */
class IsRequest extends FormRequest
{
    use OzellestirmeAlanlari;

    /** Yüklenen Junos dosyasının TFTP'deki adı: payload() kaç kez çağrılırsa çağrılsın aynı. */
    private ?string $geciciAd = null;

    public function authorize(): bool
    {
        return true; // rota kapısı soruyor (can:konsol:create)
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $cihaz = (string) $this->input('ad');
        $yalnizYedek = $this->input('islem') === 'yedek';
        /* Bilgisayardan seçilen dosya, o iş için listedeki seçimin yerine geçer. */
        $yedekYuklendi = $this->hasFile('yedek_yukle');
        $goruntuYuklendi = $this->hasFile('goruntu_yukle');

        return [
            'islem' => ['nullable', Rule::in(array_keys(ConsoleJob::ISLEMLER))],
            /* Formda uuid; açık iş denetimi burada ekranda iz bırakır, kilitli asıl denetim JobOpener'da. */
            'pc' => ['required', 'uuid', Rule::exists('konsol_pcleri', 'uuid'), function (string $alan, mixed $deger, Closure $hata): void {
                $pc = $this->pcYaDaNull();
                if ($pc !== null && $pc->isler()->acik()->where('acik_port', $this->secilenPort())->exists()) {
                    $hata(ConsoleJob::portMesgul($this->secilenPort()));
                }
            }],
            /*
             * Seri port: listedekilerden biri. "Otomatik" yok; boş gelirse
             * PC'nin tek kablosu (varsayilanPort). Tek kablo yoksa seçmek şart:
             * ajanın taraması öbür işin kablosunu ya da kasanın yerleşik
             * portunu bulabilirdi.
             */
            'seri_port' => [Rule::requiredIf(fn (): bool => ($this->pcYaDaNull()?->varsayilanPort() ?? '') === ''), 'nullable', 'string', 'max:200',
                function (string $alan, mixed $deger, Closure $hata): void {
                    if (! in_array((string) $deger, $this->pcYaDaNull()?->secilebilirPortlar() ?? [], true)) {
                        $hata('Bu port PC\'de tanımlı değil.');
                    }
                }],
            /*
             * Cihaz listeden (yedek klasörleri) ya da boş. Boşsa switch fabrika
             * ayarında kalır; seçilirse o cihazın bir yedeği yüklenir. Yalnız
             * yedek yüklemede cihaz şart: yüklenecek başka bir şey yok.
             */
            'ad' => [Rule::requiredIf($yalnizYedek && ! $yedekYuklendi), 'nullable', 'string', 'max:120', function (string $alan, mixed $deger, Closure $hata): void {
                if ((string) $deger !== '' && ! in_array((string) $deger, app(ConfigurationSource::class)->cihazlar(), true)) {
                    $hata('Cihazı listeden seçin.');
                }
            }],
            'adres_turu' => ['nullable', Rule::in(array_keys(ConsoleJob::ADRES_TURLERI))],
            /* Aynı PC'de aynı anda kurulan iki switch aynı kurulum adresini alamaz (loader ya da CLI). */
            'cihaz_ip' => ['nullable', 'string', 'regex:/^(\d{1,3}\.){3}\d{1,3}\/([8-9]|[12]\d|30)$/', function (string $alan, mixed $deger, Closure $hata) use ($yalnizYedek): void {
                $pc = $this->pcYaDaNull();
                $adres = trim((string) $deger) ?: (string) $pc?->cihaz_ip;
                if ($pc !== null && ! $yalnizYedek && $this->input('adres_turu') !== 'dhcp'
                    && $pc->isler()->acik()->where('cihaz_ip', $adres)->exists()) {
                    $hata("Bu PC'de açık başka bir iş {$adres} kurulum adresini kullanıyor; bu switch'e başka bir adres verin.");
                }
            }],
            'gecit' => ['nullable', 'ip'],
            'goruntu' => [Rule::requiredIf(! $yalnizYedek && ! $goruntuYuklendi), 'nullable', 'string', function (string $alan, mixed $deger, Closure $hata) use ($yalnizYedek, $goruntuYuklendi): void {
                if (! $yalnizYedek && ! $goruntuYuklendi) {
                    $g = app(ImageSource::class)->bul((string) $deger);
                    if ($g === null) {
                        $hata('Görüntü listede yok.');
                    } elseif ($this->input('islem') !== 'cli' && ($neden = AgentJob::konsoldanKurulamaz($g['dosya'])) !== null) {
                        $hata($neden);
                    }
                }
            }],
            'yedek_dosya' => [Rule::requiredIf($cihaz !== '' && ! $yedekYuklendi), 'nullable', 'string', 'max:255',
                function (string $alan, mixed $deger, Closure $hata) use ($cihaz, $yedekYuklendi): void {
                    if ($cihaz !== '' && ! $yedekYuklendi && ! in_array((string) $deger, array_column(app(ConfigurationSource::class)->yedekler($cihaz), 'dosya'), true)) {
                        $hata('Bu yedek dosyası o cihazın klasöründe yok.');
                    }
                }],
            'root_parola' => ['nullable', 'string', 'max:128'],
            'yedek_yukle' => ['nullable', 'file', 'max:'.(YedekDosyasi::EN_BUYUK / 1024), function (string $alan, mixed $deger, Closure $hata): void {
                if ($deger instanceof UploadedFile && YedekDosyasi::metin($deger) === null) {
                    $hata('Dosya bir Junos yapılandırması değil (system bloğu yok) ya da 8 MB\'tan büyük.');
                }
            }],
            /* Ad TFTP adresine ve loader komutuna yazılır: yalnız güvenli karakterler. */
            'goruntu_saglama' => ['nullable', 'string', 'regex:'.JunosGoruntusu::SAGLAMA],
            'goruntu_yukle' => ['nullable', 'file', 'max:'.((int) config('junos.upload_max_mb') * 1024), new JunosGoruntusu($this->input('goruntu_saglama')), function (string $alan, mixed $deger, Closure $hata): void {
                if ($deger instanceof UploadedFile && $this->input('islem') !== 'cli' && ($neden = AgentJob::konsoldanKurulamaz($deger->getClientOriginalName())) !== null) {
                    $hata($neden);
                }
                if ($deger instanceof UploadedFile && preg_match('/^[A-Za-z0-9._-]{1,200}\.tgz$/', $deger->getClientOriginalName()) !== 1) {
                    $hata('Junos dosyası .tgz olmalı ve adında yalnız harf, rakam, nokta, tire bulunmalı (ör. jinstall-ex-3200-12.3R12.4-domestic-signed.tgz).');
                }
            }],
        ] + $this->ozellestirmeKurallari();
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'ad.required' => 'Yalnız yedek yüklemede yedeği yüklenecek cihazı seçin ya da bilgisayardan bir yedek dosyası seçin.',
            'goruntu.required' => 'Junos görüntüsünü listeden seçin ya da bilgisayardan bir Junos dosyası seçin.',
            'cihaz_ip.regex' => 'Adres önekle yazılmalı: 198.51.100.120/24.',
            'yedek_dosya.required' => 'Yüklenecek yedeği seçin.',
            'goruntu_saglama.regex' => 'Sağlama MD5 (32), SHA-1 (40) ya da SHA-256 (64) onaltılık karakter olmalı.',
            'seri_port.required' => 'Konsol kablosunun takılı olduğu seri portu seçin ("Portları listele" PC\'ye takılı olanları gösterir).',
        ] + $this->ozellestirmeIletileri();
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        $yalnizYedek = $this->input('islem') === 'yedek';
        $g = $yalnizYedek ? null : ($this->yuklenenGoruntu() ?? app(ImageSource::class)->bul((string) $this->input('goruntu')));
        $cihaz = $this->string('ad')->value();
        $yedekMetni = $this->hasFile('yedek_yukle') ? YedekDosyasi::metin($this->file('yedek_yukle')) : null;
        $yedekAdi = $yedekMetni === null ? '' : mb_substr($this->file('yedek_yukle')->getClientOriginalName(), 0, 120);

        $pc = $this->pc();
        $dhcp = $this->input('adres_turu') === 'dhcp';

        $kurulum = $g === null ? [
            /* Yalnız yedek: görüntü ve loader adresi yok. */
            'adres_turu' => 'sabit',
            'cihaz_ip' => null,
            'gecit' => null,
            'goruntu_etiket' => null,
            'goruntu_adres' => null,
            'beklenen_surum' => null,
        ] : [
            /* Boş bırakılırsa PC kaydındaki adres. */
            'adres_turu' => $dhcp ? 'dhcp' : 'sabit',
            'cihaz_ip' => $dhcp ? null : ($this->string('cihaz_ip')->trim()->value() ?: $pc->cihaz_ip),
            'gecit' => $dhcp ? null : ($this->string('gecit')->trim()->value() ?: $pc->gecit),
            'goruntu_etiket' => $g['etiket'],
            'goruntu_adres' => $g['adres'],
            'gecici_goruntu' => $g['gecici'] ?? null,
            'beklenen_surum' => AgentJob::surum($g['dosya']),
        ];

        return $kurulum + [
            'pc_id' => $pc->id,
            'seri_port' => $this->secilenPort(),
            'acik_port' => $this->secilenPort(),
            'islem' => $yalnizYedek ? 'yedek' : ($this->input('islem') === 'cli' ? 'cli' : 'kurulum'),
            'ad' => $cihaz ?: (pathinfo($yedekAdi, PATHINFO_FILENAME) ?: 'Yeni cihaz'),
            'root_parola' => $this->filled('root_parola') ? (string) $this->input('root_parola') : null,
        ] + match (true) {
            /* Bilgisayardan yüklenen yedek; metni işte (şifreli) durur. */
            $yedekMetni !== null => ['yapilandirma_turu' => 'dosya', 'yedek_cihaz' => $yedekAdi, 'yedek_dosya' => null, 'yedek_metni' => $yedekMetni],
            /* Listeden cihaz: onun seçilen yedeği. */
            $cihaz !== '' => ['yapilandirma_turu' => 'yedek', 'yedek_cihaz' => $cihaz, 'yedek_dosya' => $this->string('yedek_dosya')->value()],
            /* Hiçbiri: fabrika ayarında kalır. */
            default => ['yapilandirma_turu' => 'yok', 'yedek_cihaz' => null, 'yedek_dosya' => null],
        } + $this->yama($cihaz, $yedekMetni);
    }

    /**
     * Bilgisayardan seçilen Junos dosyası yalnız bu iş için TFTP dizinine
     * benzersiz bir adla konur (JobOpener yazar, iş kapanınca silinir).
     *
     * @return array{etiket: string, adres: string, dosya: string, gecici: string}|null
     */
    private function yuklenenGoruntu(): ?array
    {
        if (! $this->hasFile('goruntu_yukle')) {
            return null;
        }

        $ad = $this->file('goruntu_yukle')->getClientOriginalName();
        $this->geciciAd ??= 'konsol-'.Str::lower(Str::random(8)).'-'.$ad;

        return [
            'etiket' => 'Bilgisayardan · '.$ad,
            'adres' => rtrim((string) config('junos.tftp'), '/').'/'.$this->geciciAd,
            'dosya' => $ad,
            'gecici' => $this->geciciAd,
        ];
    }

    /**
     * Yedeğin üstüne uygulanacak yama (yalnız listeden cihaz seçildiyse ve
     * özelleştirme istendiyse). Parola özetleri burada üretilir; düz metin
     * saklanmaz.
     *
     * @return array{yama: ?string, yama_ozeti: ?string, uplink_arayuz: ?string}
     */
    private function yama(string $cihaz, ?string $metin): array
    {
        $oz = $cihaz === '' && $metin === null ? null : $this->ozellestirme();
        $dosya = $this->string('yedek_dosya')->value();
        $satirlar = $oz === null ? [] : app(Ozellestirme::class)->yama($cihaz, $dosya, $oz, $metin);

        return [
            'yama' => $satirlar === [] ? null : implode("\n", $satirlar),
            'yama_ozeti' => $satirlar === [] ? null : mb_substr(app(Ozellestirme::class)->ozet($cihaz, $dosya, $oz, $metin), 0, 500),
            'uplink_arayuz' => $satirlar === [] ? null : (($oz['uplink_arayuz'] ?? '') ?: null),
        ];
    }

    public function pc(): ConsolePc
    {
        return ConsolePc::query()->where('uuid', $this->string('pc')->value())->firstOrFail();
    }

    private function pcYaDaNull(): ?ConsolePc
    {
        return ConsolePc::query()->where('uuid', $this->string('pc')->value())->first();
    }

    /**
     * İşin portu: seçilen; seçilmediyse PC'nin tek kablosu (doğrulama
     * ikisinden birini şart koşar). Aynı porta ikinci açık iş bu değerle aranır.
     */
    public function secilenPort(): string
    {
        return $this->string('seri_port')->trim()->value() ?: (string) $this->pcYaDaNull()?->varsayilanPort();
    }
}
