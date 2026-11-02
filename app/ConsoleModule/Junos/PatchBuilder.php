<?php

declare(strict_types=1);

namespace App\ConsoleModule\Junos;

/**
 * Konsol işinin "Yapılandırmayı özelleştir" bölümü: yedekteki değerleri okur
 * (formu doldurmak için) ve formdan, yedeğin ÜSTÜNE uygulanacak `set` /
 * `delete` yamasını üretir. Ajan yamayı yedekle AYNI commit'te uygular
 * (`load override` → `load set terminal` → commit): ya ikisi birden girer ya
 * hiçbiri. Ekrandaki önizleme ile ajana giden yama bu sınıftan çıkar: biri
 * değişip öbürü eski kalmasın.
 *
 * Alan boşsa ona dokunulmaz; liste alanları (whitelist, koru, vlan.0) verilirse
 * bütünüyle DEĞİŞTİRİR (önce delete, sonra set): yedekteki eski girişlerden
 * hangisinin kalacağını tahmin etmek yerine formda görünen liste geçerlidir.
 */
final class PatchBuilder
{
    /** Yedekte bu filtreler varsa whitelist alanları açılır. */
    public const FILTRELER = ['re_filter' => 'RE_FILTER', 'me_filter' => 'ME_FILTER'];

    public const KORU = 'koru';

    /**
     * Formun ön değerleri ve hangi bölümlerin yedekte olduğu.
     *
     * @return array<string, mixed>
     */
    public function alanlar(ConfigTree $t): array
    {
        $uplink = $this->uplink($t);
        $alanlar = [
            'host_name' => (string) $t->deger('system host-name'),
            'uplink_arayuz' => (string) $uplink,
            'uplink_adres' => $uplink === null ? '' : (string) $t->deger("interfaces {$uplink} unit 0 family inet address"),
            'uplink_gecit' => (string) $t->deger('routing-options static route 0.0.0.0/0 next-hop'),
            'me0_adres' => (string) $t->deger('interfaces me0 unit 0 family inet address'),
            'vlan0_adresleri' => $t->degerler('interfaces vlan unit 0 family inet address'),
            'koru' => $t->degerler('policy-options prefix-list '.self::KORU),
            'snmp_ad' => (string) $t->deger('snmp name'),
            'snmp_aciklama' => (string) $t->deger('snmp description'),
            'snmp_communityler' => $this->communityler($t),
            'snmp_engine' => $t->deger('snmp engine-id local') !== null,
            'archival' => $t->varMi('system archival'),
            'admin_var' => $t->varMi('system login user admin'),
        ];

        foreach (self::FILTRELER as $alan => $filtre) {
            $term = $this->kabulTerimi($t, $filtre);
            $alanlar[$alan] = $term === null ? null : $t->degerler("firewall family inet filter {$filtre} term {$term} from source-address");
        }

        return $alanlar;
    }

    /**
     * @param  array<string, mixed>  $oz  doğrulanmış form (IsRequest::ozellestirme)
     * @return list<string>
     */
    public function satirlar(ConfigTree $t, array $oz): array
    {
        $oz = $this->degisenler($t, $oz);
        $s = [];

        if (($oz['host_name'] ?? '') !== '') {
            $s[] = "set system host-name {$oz['host_name']}";
        }

        array_push($s, ...$this->uplinkSatirlari($t, $oz));

        if ($oz['me0_kaldir'] ?? false) {
            $s[] = 'delete interfaces me0 unit 0 family inet address';
        } elseif (($oz['me0_adres'] ?? '') !== '') {
            $s[] = 'delete interfaces me0 unit 0 family inet address';
            $s[] = "set interfaces me0 unit 0 family inet address {$oz['me0_adres']}";
        }

        if (isset($oz['vlan0_adresleri'])) {
            $s[] = 'delete interfaces vlan unit 0 family inet address';
            foreach ($oz['vlan0_adresleri'] as $a) {
                $s[] = "set interfaces vlan unit 0 family inet address {$a}";
            }
        }

        foreach (self::FILTRELER as $alan => $filtre) {
            $term = $this->kabulTerimi($t, $filtre);
            if (isset($oz[$alan]) && $term !== null) {
                $yol = "firewall family inet filter {$filtre} term {$term} from source-address";
                $s[] = "delete {$yol}";
                foreach ($oz[$alan] as $a) {
                    $s[] = "set {$yol} {$a}";
                }
            }
        }

        if (isset($oz['koru'])) {
            $s[] = 'delete policy-options prefix-list '.self::KORU;
            foreach ($oz['koru'] as $a) {
                $s[] = 'set policy-options prefix-list '.self::KORU." {$a}";
            }
        }

        array_push($s, ...$this->snmpSatirlari($t, $oz));

        if (($oz['root_ozet'] ?? '') !== '') {
            $s[] = "set system root-authentication encrypted-password \"{$oz['root_ozet']}\"";
        }
        if (($oz['admin_ozet'] ?? '') !== '') {
            if (! $t->varMi('system login user admin')) {
                $s[] = 'set system login user admin class super-user';
            }
            $s[] = "set system login user admin authentication encrypted-password \"{$oz['admin_ozet']}\"";
        }

        if ($oz['archival_kaldir'] ?? false) {
            $s[] = 'delete system archival';
        }

        array_push($s, ...($oz['ek_komutlar'] ?? []));

        return $s;
    }

    /**
     * Ekranda ve denetim kaydında: hangi bölümler değişiyor. Değer yok, sır yok.
     *
     * @param  array<string, mixed>  $oz
     */
    public function ozet(ConfigTree $t, array $oz): string
    {
        $oz = $this->degisenler($t, $oz);
        $adlar = array_filter([
            'makine adı' => ($oz['host_name'] ?? '') !== '',
            'uplink '.($oz['uplink_arayuz'] ?? '') => ($oz['uplink_arayuz'] ?? '') !== '' && ($oz['uplink_adres'] ?? '') !== '',
            'me0' => ($oz['me0_kaldir'] ?? false) || ($oz['me0_adres'] ?? '') !== '',
            'vlan.0 adresleri' => isset($oz['vlan0_adresleri']),
            'RE_FILTER '.count($oz['re_filter'] ?? []).' adres' => isset($oz['re_filter']),
            'ME_FILTER '.count($oz['me_filter'] ?? []).' adres' => isset($oz['me_filter']),
            'koru '.count($oz['koru'] ?? []).' blok' => isset($oz['koru']),
            'SNMP' => ($oz['snmp_ad'] ?? '') !== '' || ($oz['snmp_aciklama'] ?? '') !== '' || ($oz['snmp_engine_kaldir'] ?? false) || isset($oz['snmp_communityler']),
            'root parolası' => ($oz['root_ozet'] ?? '') !== '',
            'admin parolası' => ($oz['admin_ozet'] ?? '') !== '',
            'archival kaldırıldı' => $oz['archival_kaldir'] ?? false,
            count($oz['ek_komutlar'] ?? []).' ek komut' => isset($oz['ek_komutlar']),
        ]);

        return implode(', ', array_keys($adlar));
    }

    /**
     * Form yedekteki değerlerle dolu gelir: yedekteki değerle aynı kalan alan
     * "dokunma" demektir, yamaya satır üretmez.
     *
     * @param  array<string, mixed>  $oz
     * @return array<string, mixed>
     */
    private function degisenler(ConfigTree $t, array $oz): array
    {
        $m = $this->alanlar($t);

        foreach (['host_name', 'me0_adres', 'snmp_ad', 'snmp_aciklama'] as $alan) {
            if (($oz[$alan] ?? '') === $m[$alan]) {
                $oz[$alan] = '';
            }
        }
        if (($oz['uplink_arayuz'] ?? '') === $m['uplink_arayuz'] && ($oz['uplink_adres'] ?? '') === $m['uplink_adres']
            && in_array($oz['uplink_gecit'] ?? '', ['', $m['uplink_gecit']], true)) {
            $oz['uplink_arayuz'] = $oz['uplink_adres'] = $oz['uplink_gecit'] = '';
        }
        foreach (['vlan0_adresleri', 'koru', 're_filter', 'me_filter'] as $alan) {
            if (isset($oz[$alan]) && self::ayniKume($oz[$alan], (array) ($m[$alan] ?? []))) {
                unset($oz[$alan]);
            }
        }

        return $oz;
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    private static function ayniKume(array $a, array $b): bool
    {
        sort($a);
        sort($b);

        return $a === $b;
    }

    /**
     * Önizleme ve denetim için: parola özetleri gösterilmez.
     *
     * @param  list<string>  $satirlar
     * @return list<string>
     */
    public static function maskele(array $satirlar): array
    {
        return array_map(fn (string $s): string => (string) preg_replace('/(encrypted-password )"[^"]*"/', '$1"<gizli>"', $s), $satirlar);
    }

    /**
     * Uplink: varsayılan rotanın geçidini içeren inet adresli arayüz; yoksa
     * açıklaması UPLINK olan.
     */
    public function uplink(ConfigTree $t): ?string
    {
        $gecit = $t->deger('routing-options static route 0.0.0.0/0 next-hop');
        $aday = null;

        foreach ($t->adlar('interfaces') as $ad) {
            if (in_array($ad, ['vlan', 'me0', 'lo0', 'vme'], true) || str_contains($ad, ' ')) {
                continue;
            }
            foreach ($t->degerler("interfaces {$ad} unit 0 family inet address") as $adres) {
                if ($gecit !== null && self::altAgda($gecit, $adres)) {
                    return $ad;
                }
            }
            if ($aday === null && strtoupper((string) $t->deger("interfaces {$ad} description")) === 'UPLINK') {
                $aday = $ad;
            }
        }

        return $aday;
    }

    public static function altAgda(string $ip, string $cidr): bool
    {
        [$ag, $onek] = array_pad(explode('/', $cidr, 2), 2, '32');
        $maske = (int) $onek === 0 ? 0 : (~0 << (32 - (int) $onek)) & 0xFFFFFFFF;

        return ip2long($ip) !== false && ip2long($ag) !== false && (ip2long($ip) & $maske) === (ip2long($ag) & $maske);
    }

    /**
     * @param  array<string, mixed>  $oz
     * @return list<string>
     */
    private function uplinkSatirlari(ConfigTree $t, array $oz): array
    {
        $yeni = (string) ($oz['uplink_arayuz'] ?? '');
        if ($yeni === '' || ($oz['uplink_adres'] ?? '') === '') {
            return [];
        }

        $eski = $this->uplink($t);
        $filtre = $eski === null ? null : $t->deger("interfaces {$eski} unit 0 family inet filter output");
        $s = [];

        if ($eski !== null && $eski !== $yeni) {
            /* Eski uplink sıradan switch portuna döner. */
            $s[] = "delete interfaces {$eski}";
            $s[] = "set interfaces {$eski} unit 0 family ethernet-switching";
        }

        /* Junos: ethernet-switching olan arayüzde inet olamaz; VLAN üyeliği de düşmeli. */
        $s[] = "delete interfaces {$yeni} unit 0 family ethernet-switching";
        foreach ($t->adlar('vlans') as $vlan) {
            if (array_intersect($t->degerler("vlans {$vlan} interface"), [$yeni, "{$yeni}.0"]) !== []) {
                $s[] = "delete vlans {$vlan} interface {$yeni}.0";
            }
        }
        $s[] = "delete interfaces {$yeni} unit 0 family inet address";
        $s[] = "set interfaces {$yeni} description UPLINK";
        $s[] = "set interfaces {$yeni} unit 0 family inet address {$oz['uplink_adres']}";
        if ($filtre !== null) {
            $s[] = "set interfaces {$yeni} unit 0 family inet filter output {$filtre}";
        }

        if (($oz['uplink_gecit'] ?? '') !== '') {
            $s[] = 'delete routing-options static route 0.0.0.0/0';
            $s[] = "set routing-options static route 0.0.0.0/0 next-hop {$oz['uplink_gecit']}";
        }

        return $s;
    }

    /**
     * @param  array<string, mixed>  $oz
     * @return list<string>
     */
    private function snmpSatirlari(ConfigTree $t, array $oz): array
    {
        $s = [];

        if (($oz['snmp_ad'] ?? '') !== '') {
            $s[] = "set snmp name \"{$oz['snmp_ad']}\"";
        }
        if (($oz['snmp_aciklama'] ?? '') !== '') {
            $s[] = "set snmp description \"{$oz['snmp_aciklama']}\"";
        }
        if ($oz['snmp_engine_kaldir'] ?? false) {
            $s[] = 'delete snmp engine-id';
        }
        if (isset($oz['snmp_communityler'])) {
            foreach (array_diff($this->communityler($t), $oz['snmp_communityler']) as $c) {
                $s[] = "delete snmp community {$c}";
            }
            foreach (array_diff($oz['snmp_communityler'], $this->communityler($t)) as $c) {
                $s[] = "set snmp community {$c} authorization read-only";
            }
        }

        return $s;
    }

    /** @return list<string> */
    private function communityler(ConfigTree $t): array
    {
        $adlar = [];
        foreach ($t->adlar('snmp') as $ifade) {
            if (preg_match('/^community (\S+)$/', $ifade, $m)) {
                $adlar[] = $m[1];
            }
        }

        return $adlar;
    }

    /** Filtrenin kaynak adres listesini taşıyan ilk terimi (RE_FILTER → Kabul). */
    private function kabulTerimi(ConfigTree $t, string $filtre): ?string
    {
        foreach ($t->adlar("firewall family inet filter {$filtre}") as $ifade) {
            if (preg_match('/^term (\S+)$/', $ifade, $m) && $t->degerler("firewall family inet filter {$filtre} term {$m[1]} from source-address") !== []) {
                return $m[1];
            }
        }

        return null;
    }
}
