<?php

namespace Database\Factories;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'aktif' => true,
        ];
    }

    /** Yönetici rolü; yoksa yaratılır (sınamalar göçe bağlı kalmasın). */
    public function admin(): static
    {
        return $this->state(fn (array $attributes): array => [
            'rol_id' => Role::firstOrCreate(
                ['slug' => 'admin'],
                ['label' => 'Yönetici', 'badge' => 'purple', 'yonetici' => true, 'yerlesik' => true, 'sira' => 1],
            )->id,
        ]);
    }

    /** Belirli bir rolle. */
    public function rol(string $slug): static
    {
        return $this->state(fn (array $attributes): array => [
            'rol_id' => Role::where('slug', $slug)->value('id'),
        ]);
    }

    /**
     * Yedekleri görüp indirebilen kişi — rolü vermese de kişiye özel
     * istisnayla (eski perm_yedek bayrağının karşılığı).
     */
    public function yedekci(): static
    {
        return $this->afterCreating(function (User $user): void {
            foreach (['view', 'download'] as $aksiyon) {
                $user->izinler()->create(['kaynak' => 'yedek', 'aksiyon' => $aksiyon, 'etki' => 'izin']);
            }
        });
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'aktif' => false,
        ]);
    }
}
