<?php
declare(strict_types=1);

namespace FacturaFacil;

use RuntimeException;

/** All monetary calculations use integers; input quantities have at most 3 decimals. */
final class Money
{
    private const MAX_CENTS = 999999999999;

    public static function parse(string $value): int
    {
        return self::decimal($value, 2, self::MAX_CENTS, 'precio');
    }

    public static function format(int $cents): string
    {
        return ($cents < 0 ? '-' : '') . intdiv(abs($cents), 100) . '.' . str_pad((string)(abs($cents) % 100), 2, '0', STR_PAD_LEFT);
    }

    private static function decimal(string $value, int $places, int $maximum, string $label): int
    {
        $value = trim($value);
        // No thousands separators: 1.234 / 1,234 are ambiguous for money and rejected.
        if (!preg_match('/^\d+(?:[.,]\d{1,' . $places . '})?$/D', $value)) {
            throw new RuntimeException('El ' . $label . ' debe ser un número positivo, sin separador de miles y con hasta ' . $places . ' decimales.');
        }
        [$whole, $fraction] = array_pad(explode('.', str_replace(',', '.', $value), 2), 2, '');
        $whole = ltrim($whole, '0');
        $whole = $whole === '' ? '0' : $whole;
        if (strlen($whole) > 12) {
            throw new RuntimeException('El ' . $label . ' supera el máximo permitido.');
        }
        $scale = 10 ** $places;
        $number = (int)$whole * $scale + (int)str_pad($fraction, $places, '0');
        if ($number > $maximum) {
            throw new RuntimeException('El ' . $label . ' supera el máximo permitido.');
        }
        return $number;
    }

    private static function rounded(int $numerator, int $denominator): int
    {
        return intdiv($numerator + intdiv($denominator, 2), $denominator);
    }

    public static function calculate(array $items, bool $pricesIncludeVat = true, bool $invoiceC = false): array
    {
        if (!$items || count($items) > 200) {
            throw new RuntimeException('La factura debe tener entre 1 y 200 productos.');
        }
        $normalized = [];
        $groups = [];
        $total = 0;
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new RuntimeException('Producto de factura inválido.');
            }
            $name = trim((string)($item['name'] ?? ''));
            if ($name === '' || strlen($name) > 300) {
                throw new RuntimeException('Cada producto necesita un nombre de hasta 300 caracteres.');
            }
            $rate = str_replace(',', '.', (string)($item['vat_rate'] ?? ''));
            if (!in_array($rate, ['10.5', '21'], true)) {
                throw new RuntimeException('El IVA del producto debe ser 10,5% o 21%.');
            }
            $quantity = self::decimal((string)($item['quantity'] ?? '1'), 3, 1000000000, 'cantidad');
            if ($quantity <= 0) {
                throw new RuntimeException('La cantidad debe ser mayor que cero.');
            }
            $price = self::parse((string)($item['price'] ?? ''));
            // Guard before integer multiplication (64-bit PHP is required by installation).
            if ($price > 0 && $quantity > intdiv(PHP_INT_MAX - 1000, $price)) {
                throw new RuntimeException('El importe del producto supera el máximo permitido.');
            }
            $line = self::rounded($price * $quantity, 1000);
            if ($line > self::MAX_CENTS) {
                throw new RuntimeException('El importe del producto supera el máximo permitido.');
            }
            $rateTenths = $rate === '21' ? 210 : 105;
            if ($invoiceC) {
                $net = $line;
                $vat = 0;
            } elseif ($pricesIncludeVat) {
                $net = self::rounded($line * 1000, 1000 + $rateTenths);
                $vat = $line - $net;
            } else {
                $net = $line;
                $vat = self::rounded($net * $rateTenths, 1000);
                $line = $net + $vat;
            }
            $total += $line;
            if ($total > self::MAX_CENTS) {
                throw new RuntimeException('El total de factura supera el máximo permitido.');
            }
            $normalized[] = [
                'product_id' => isset($item['product_id']) && $item['product_id'] !== '' ? (int)$item['product_id'] : null,
                'code' => trim((string)($item['code'] ?? '')),
                'name' => $name,
                'quantity' => rtrim(rtrim(intdiv($quantity, 1000) . '.' . str_pad((string)($quantity % 1000), 3, '0', STR_PAD_LEFT), '0'), '.'),
                'unit_price_cents' => $price,
                'vat_rate' => $rate,
                'net_cents' => $net,
                'vat_cents' => $vat,
                'total_cents' => $line,
            ];
            if (!$invoiceC) {
                $groups[$rate] ??= ['rate' => $rate, 'base_cents' => 0, 'vat_cents' => 0];
                $groups[$rate]['base_cents'] += $net;
                $groups[$rate]['vat_cents'] += $vat;
            }
        }
        // Reconcile at tax-rate level. Repeated very small lines must not lose VAT
        // simply because each individual line rounded down independently.
        if (!$invoiceC) {
            foreach ($groups as $rate => &$group) {
                $rateTenths = (string)$rate === '21' ? 210 : 105;
                $groupGross = $group['base_cents'] + $group['vat_cents'];
                $targetNet = $pricesIncludeVat ? self::rounded($groupGross * 1000, 1000 + $rateTenths) : $group['base_cents'];
                $targetVat = $pricesIncludeVat ? $groupGross - $targetNet : self::rounded($targetNet * $rateTenths, 1000);
                $adjustment = $targetVat - $group['vat_cents'];
                foreach ($normalized as &$lineItem) {
                    if ($lineItem['vat_rate'] !== (string)$rate || $adjustment === 0) {
                        continue;
                    }
                    // Spread the few rounding cents while keeping every line nonnegative.
                    $change = $adjustment > 0
                        ? ($pricesIncludeVat ? min($adjustment, $lineItem['net_cents'], 1) : min($adjustment, 1))
                        : -min(-$adjustment, $lineItem['vat_cents'], 1);
                    $lineItem['vat_cents'] += $change;
                    if ($pricesIncludeVat) {
                        $lineItem['net_cents'] -= $change;
                    } else {
                        $lineItem['total_cents'] += $change;
                        $total += $change;
                    }
                    $adjustment -= $change;
                }
                unset($lineItem);
                $group['base_cents'] = $targetNet;
                $group['vat_cents'] = $targetVat;
            }
            unset($group);
        }
        if ($total > self::MAX_CENTS) {
            throw new RuntimeException('El total de factura supera el máximo permitido.');
        }
        $net = array_sum(array_column($normalized, 'net_cents'));
        $vat = array_sum(array_column($normalized, 'vat_cents'));
        return ['items' => $normalized, 'net_cents' => $net, 'vat_cents' => $vat, 'total_cents' => $total, 'vat_breakdown' => array_values($groups)];
    }
}
