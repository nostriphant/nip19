<?php

namespace nostriphant\NIP19;

readonly class Bech32 {

    public string $type;
    public array $data;

    public function __construct(private string $bech32) {
        list($this->type, $decoded) = self::decodeRaw($this->bech32, 5000);
        $this->data = match ($this->type) {
            'nsec' => [self::fromBytesToHex(self::decodeBits($decoded))],
            'npub' => [self::fromBytesToHex(self::decodeBits($decoded))],
            'note' => [self::fromBytesToHex(self::decodeBits($decoded))],
            'nprofile' => self::parseTLVNProfile($decoded),
            'naddr' => self::parseTLVNAddr($decoded),
            'nevent' => self::parseTLVNEvent($decoded)
        };
    }

    static function __callStatic(string $name, array $arguments): self {
        return new self(self::encodeRaw($name, match ($name) {
                    'nsec' => self::fromHexToBytes($arguments[0]),
                    'npub' => self::fromHexToBytes($arguments[0]),
                    'note' => self::fromHexToBytes($arguments[0]),
                    'nprofile' => self::encodeTLV(
                            [self::fromHexToBytes($arguments['pubkey'])],
                            array_map([self::class, 'fromUTF8ToBytes'], $arguments['relays'] ?? [])
                    ),
                    'naddr' => self::encodeTLV(
                            [self::fromUTF8ToBytes($arguments['identifier'])],
                            array_map([self::class, 'fromUTF8ToBytes'], $arguments['relays'] ?? []),
                            [self::fromHexToBytes($arguments['pubkey'])],
                            [self::fromIntegerToBytes($arguments['kind'])],
                    ),
                    'nevent' => self::encodeTLV(
                            [self::fromHexToBytes($arguments['id'])],
                            array_map([self::class, 'fromUTF8ToBytes'], $arguments['relays'] ?? []),
                            isset($arguments['author']) ? [self::fromHexToBytes($arguments['author'])] : [],
                            [self::fromIntegerToBytes($arguments['kind'])],
                    )
                }));
    }

    public function __toString(): string {
        return $this->bech32;
    }

    static function array_entries(array $array) {
        return array_map(fn(mixed $key, mixed $value) => [$key, $value], array_keys($array), array_values($array));
    }

    static function parseTLVNProfile(array $data): array {
        $tlv = self::parseTLV($data);
        if (!isset($tlv[0]) || !isset($tlv[0][0])) {
            throw new \Exception('missing TLV 0 for nprofile');
        }
        if (count($tlv[0][0]) !== 32) {
            throw new \Exception('TLV 0 should be 32 bytes');
        }

        return [
            "pubkey" => self::fromBytesToHex($tlv[0][0]),
            "relays" => isset($tlv[1]) ? array_map([self::class, 'fromBytesToUTF8'], $tlv[1]) : []
        ];
    }

    static function parseTLVNAddr(array $data): array {
        $tlv = self::parseTLV($data);
        return [
            "identifier" => self::fromBytesToUTF8($tlv[0][0]),
            "pubkey" => self::fromBytesToHex($tlv[2][0]),
            "kind" => self::fromBytesToInteger($tlv[3][0]),
            "relays" => isset($tlv[1]) ? array_map([self::class, 'fromBytesToUTF8'], $tlv[1]) : []
        ];
    }

    static function parseTLVNEvent(array $data): array {
        $tlv = self::parseTLV($data);
        return [
            "id" => self::fromBytesToHex($tlv[0][0]),
            "relays" => isset($tlv[1]) ? array_map([self::class, 'fromBytesToUTF8'], $tlv[1]) : [],
            "author" => isset($tlv[2][0]) ? self::fromBytesToHex($tlv[2][0]) : null,
            "kind" => isset($tlv[3][0]) ? self::fromBytesToInteger($tlv[3][0]) : null
        ];
    }

    static function parseTLV(array $bits): array {
        $result = [];
        $rest = self::decodeBits($bits);
        while (count($rest) > 0) {
            $type = array_shift($rest);
            $length = array_shift($rest);
            $value = array_slice($rest, 0, $length);
            if (count($value) < $length) {
                throw new \Exception('not enough data to read on TLV ' . $type);
            }
            $rest = array_slice($rest, $length);
            $result[$type] = $result[$type] ?? [];
            $result[$type][] = $value;
        }
        return $result;
    }

    static function encodeTLV(array ...$tlv): array {
        return array_reduce(self::array_entries($tlv), function (array $carry, array $tlv_entry): array {
            return array_reduce($tlv_entry[1], function (array $carry, array $value) use ($tlv_entry): array {
                return array_merge($carry, [$tlv_entry[0], count($value)], $value);
            }, $carry);
        }, []);
    }

    static function fromBytesToHex(array $bytes): string {
        return array_reduce($bytes, fn(string $hex, int $item) => $hex .= str_pad(dechex($item), 2, '0', STR_PAD_LEFT), '');
    }

    static function fromBytesToInteger(array $bytes): int {
        return hexdec(self::fromBytesToHex($bytes));
    }

    static function fromBytesToUTF8(array $bytes): string {
        return array_reduce($bytes, fn(string $utf8, int $item) => $utf8 .= chr($item), '');
    }

    private static function convertBech32ToHex(#[\SensitiveParameter] string $bech32_key): string {
        try {
            return (new self($bech32_key))->data[0];
        } catch (\Exception) {
            return '';
        }
    }


    static function fromHexToBytes(#[\SensitiveParameter] string $hex_key): array {
        return array_map('hexdec', str_split($hex_key, 2));
    }

    static function fromUTF8ToBytes(string $utf8): array {
        return array_map('ord', mb_str_split($utf8));
    }

    static function fromIntegerToBytes(int $integer): array {
        // Create a Uint8Array with enough space to hold a 32-bit integer (4 bytes).
        $uint8Array = [];

        // Use bitwise operations to extract the bytes.
        $uint8Array[0] = ($integer >> 24) & 0xff; // Most significant byte (MSB)
        $uint8Array[1] = ($integer >> 16) & 0xff;
        $uint8Array[2] = ($integer >> 8) & 0xff;
        $uint8Array[3] = $integer & 0xff; // Least significant byte (LSB)

        return $uint8Array;
    }

    private static function convertHexToBech32(#[\SensitiveParameter] string $hex_key, string $prefix) {
        try {
            return self::encodeRaw($prefix, self::fromHexToBytes($hex_key));
        } catch (\Exception) {
            return '';
        }
    }

    static function toNpub(string $hex) {
        return self::convertHexToBech32($hex, 'npub');
    }

    static function fromNpub(string $npub) {
        return self::convertBech32ToHex($npub, 'npub');
    }


    static function toNsec(#[\SensitiveParameter] string $hex): string {
        return self::convertHexToBech32($hex, 'nsec');
    }

    static function fromNsec(#[\SensitiveParameter] string $nsec): string {
        return self::convertBech32ToHex($nsec, 'nsec');
    }

    static function isValid(string $expected_type, string $bech32) {
        try {
            $decoded = decode($bech32);
        } catch (\Exception $ex) {
            return false;
        }
        return $decoded[0] === $expected_type;
    }

    static function isValidNProfile(string $bech32): bool {
        return self::isValid('nprofile', $bech32);
    }

    static function isValidNAddress(string $bech32): bool {
        return self::isValid('naddress', $bech32);
    }

    static function isValidNSec(string $bech32): bool {
        return self::isValid('nsec', $bech32);
    }

    static function isValidNPub(string $bech32): bool {
        return self::isValid('npub', $bech32);
    }

    static function isValidNote(string $bech32): bool {
        return self::isValid('note', $bech32);
    }

    static function isValidNCryptSec(string $bech32): bool {
        return self::isValid('ncryptsec', $bech32);
    }

    static function isValidNEvent(string $bech32): bool {
        return self::isValid('nevent', $bech32);
    }

    const GENERATOR = [0x3b6a57b2, 0x26508e6d, 0x1ea119fa, 0x3d4233dd, 0x2a1462b3];

    static function polyMod(array $values, int $numValues): int {
        $chk = 1;
        for ($i = 0; $i < $numValues; $i++) {
            $top = $chk >> 25;
            $chk = ($chk & 0x1ffffff) << 5 ^ $values[$i];

            for ($j = 0; $j < 5; $j++) {
                $value = (($top >> $j) & 1) ? self::GENERATOR[$j] : 0;
                $chk ^= $value;
            }
        }

        return $chk;
    }

    static function hrpExpand(string $hrp, int $hrpLen): array {
        $expand1 = [];
        $expand2 = [];
        for ($i = 0; $i < $hrpLen; $i++) {
            $o = ord($hrp[$i]);
            $expand1[] = $o >> 5;
            $expand2[] = $o & 31;
        }

        return array_merge($expand1, [0], $expand2);
    }

    static function decodeBits(array $data) {
        return self::convertBits($data, 5, 8, false);
    }

    static function encodeBits(array $data) {
        return self::convertBits($data, 8, 5, true);
    }

    static function convertBits(array $data, int $fromBits, int $toBits, bool $pad = true): array {
        $inLen = count($data);
        $acc = 0;
        $bits = 0;
        $ret = [];
        $maxv = (1 << $toBits) - 1;
        $maxacc = (1 << ($fromBits + $toBits - 1)) - 1;

        for ($i = 0; $i < $inLen; $i++) {
            $value = $data[$i];
            if ($value < 0 || $value >> $fromBits) {
                throw new \Exception('Invalid value for convert bits');
            }

            $acc = (($acc << $fromBits) | $value) & $maxacc;
            $bits += $fromBits;

            while ($bits >= $toBits) {
                $bits -= $toBits;
                $ret[] = (($acc >> $bits) & $maxv);
            }
        }

        if ($pad) {
            if ($bits) {
                $ret[] = ($acc << $toBits - $bits) & $maxv;
            }
        } else if ($bits >= $fromBits || ((($acc << ($toBits - $bits))) & $maxv)) {
            throw new \Exception('Invalid data');
        }

        return $ret;
    }

    static function createChecksum(string $hrp, array $convertedDataChars): array {
        $values = array_merge(self::hrpExpand($hrp, strlen($hrp)), $convertedDataChars);
        $polyMod = self::polyMod(array_merge($values, [0, 0, 0, 0, 0, 0]), count($values) + 6) ^ 1;
        $results = [];
        for ($i = 0; $i < 6; $i++) {
            $results[$i] = ($polyMod >> 5 * (5 - $i)) & 31;
        }

        return $results;
    }

    static function verifyChecksum(string $hrp, array $convertedDataChars): bool {
        $expandHrp = self::hrpExpand($hrp, strlen($hrp));
        $r = array_merge($expandHrp, $convertedDataChars);
        return self::polyMod($r, count($r)) === 1;
    }

    const CHARSET = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';

    static function encodeRaw(string $hrp, array $bytes): string {
        $words = self::encodeBits($bytes);
        $checksum = self::createChecksum($hrp, $words);
        $characters = array_merge($words, $checksum);

        $encoded = [];
        for ($i = 0, $n = count($characters); $i < $n; $i++) {
            if (isset(self::CHARSET[$characters[$i]]) === false) {
                var_dump($hrp, $words);
            }
            $encoded[$i] = self::CHARSET[$characters[$i]];
        }

        return "{$hrp}1" . implode('', $encoded);
    }

    static function decodeRaw(string $sBech, int $limit = 90): array {
        $length = strlen($sBech);

        if ($length < 8 || $length > $limit) {
            throw new \InvalidArgumentException("invalid string length: $length ($sBech). Expected (8..$limit)");
        }
        // don't allow mixed case
        $lowered = strtolower($sBech);
        if ($sBech !== $lowered && $sBech !== strtoupper($sBech)) {
            throw new \InvalidArgumentException("String must be lowercase or uppercase");
        }

        $sepIndex = strrpos($sBech, '1');
        if ($sepIndex === false) {
            throw new \InvalidArgumentException("Letter '1' must be present between prefix and data ($sBech) only");
        }

        list($prefix, $data) = explode('1', $sBech, 2);
        if (strlen($data) < 6) {
            throw new \InvalidArgumentException('Bech32 data must be at least 6 characters long');
        }

        $words = array_map(fn(string $char) => strpos(self::CHARSET, $char), str_split(substr($data, 0, -6)));
        $sum = self::verifyChecksum($prefix, $words);
        if (str_ends_with($data, $sum) === false) {
            throw new \Exception("Invalid checksum in $sBech: expected '$sum'.");
        }

        return [$prefix, $words];
    }
}
