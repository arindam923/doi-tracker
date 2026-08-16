<?php
/**
 * Standalone QR Code → SVG (byte mode, ECC M, versions 1–6).
 * No GD, no Composer, no outbound HTTP — works on InfinityFree.
 */

function tf_qr_svg($text, $px = 320) {
    $grid = tf_qr_modules($text);
    $n = count($grid);
    $quiet = 4;
    $dim = $n + ($quiet * 2);
    $px = max(120, min(800, (int)$px));
    $out = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $dim . ' ' . $dim . '" width="' . $px . '" height="' . $px . '" shape-rendering="crispEdges" role="img" aria-label="QR code">';
    $out .= '<rect width="' . $dim . '" height="' . $dim . '" fill="#ffffff"/>';
    for ($y = 0; $y < $n; $y++) {
        for ($x = 0; $x < $n; $x++) {
            if (!empty($grid[$y][$x])) {
                $out .= '<rect x="' . ($x + $quiet) . '" y="' . ($y + $quiet) . '" width="1" height="1" fill="#0f172a"/>';
            }
        }
    }
    $out .= '</svg>';
    return $out;
}

function tf_qr_modules($text) {
    $bytes = array_values(unpack('C*', $text));
    $need = count($bytes);

    // version => [data codewords, ecc per block, block count, byte capacity]
    $versions = [
        1 => [16, 10, 1, 14],
        2 => [28, 16, 1, 26],
        3 => [44, 26, 1, 42],
        4 => [64, 18, 2, 62],
        5 => [86, 24, 2, 84],
        6 => [108, 16, 4, 106],
    ];

    $version = 0;
    foreach ($versions as $v => $meta) {
        if ($need <= $meta[3]) {
            $version = $v;
            break;
        }
    }
    if (!$version) {
        $bytes = array_slice($bytes, 0, 106);
        $need = count($bytes);
        $version = 6;
    }

    [$dataCw, $eccPerBlock, $blockCount] = $versions[$version];
    $size = 21 + (($version - 1) * 4);

    $bits = '0100' . str_pad(decbin($need), 8, '0', STR_PAD_LEFT);
    foreach ($bytes as $b) {
        $bits .= str_pad(decbin($b), 8, '0', STR_PAD_LEFT);
    }
    $bits .= '0000';
    $capacityBits = $dataCw * 8;
    if (strlen($bits) > $capacityBits) {
        $bits = substr($bits, 0, $capacityBits);
    }
    while (strlen($bits) % 8 !== 0) {
        $bits .= '0';
    }
    $pad = ['11101100', '00010001'];
    $pi = 0;
    while (strlen($bits) < $capacityBits) {
        $bits .= $pad[$pi % 2];
        $pi++;
    }

    $data = [];
    for ($i = 0; $i < $dataCw; $i++) {
        $data[] = bindec(substr($bits, $i * 8, 8));
    }

    $blockDataLen = intdiv($dataCw, $blockCount);
    $shortBlocks = $blockCount; // all equal for v1–6 M
    $blocks = [];
    $offset = 0;
    for ($b = 0; $b < $blockCount; $b++) {
        $slice = array_slice($data, $offset, $blockDataLen);
        $offset += $blockDataLen;
        $blocks[] = [
            'data' => $slice,
            'ecc' => tf_qr_rs($slice, $eccPerBlock),
        ];
    }

    $interleaved = [];
    for ($i = 0; $i < $blockDataLen; $i++) {
        for ($b = 0; $b < $blockCount; $b++) {
            $interleaved[] = $blocks[$b]['data'][$i];
        }
    }
    for ($i = 0; $i < $eccPerBlock; $i++) {
        for ($b = 0; $b < $blockCount; $b++) {
            $interleaved[] = $blocks[$b]['ecc'][$i];
        }
    }

    $moduleBits = '';
    foreach ($interleaved as $cw) {
        $moduleBits .= str_pad(decbin($cw), 8, '0', STR_PAD_LEFT);
    }
    $remainder = [0, 0, 7, 7, 7, 7, 7];
    $moduleBits .= str_repeat('0', $remainder[$version]);

    $reserved = tf_qr_reserved($size, $version);
    $best = null;
    $bestScore = PHP_INT_MAX;
    for ($mask = 0; $mask < 8; $mask++) {
        $grid = tf_qr_place($size, $version, $moduleBits, $reserved, $mask);
        $score = tf_qr_penalty($grid);
        if ($score < $bestScore) {
            $bestScore = $score;
            $best = $grid;
        }
    }
    return $best;
}

function tf_qr_gf() {
    static $exp = null, $log = null;
    if ($exp !== null) return [$exp, $log];
    $exp = array_fill(0, 512, 0);
    $log = array_fill(0, 256, 0);
    $x = 1;
    for ($i = 0; $i < 255; $i++) {
        $exp[$i] = $x;
        $log[$x] = $i;
        $x <<= 1;
        if ($x & 0x100) $x ^= 0x11d;
    }
    for ($i = 255; $i < 512; $i++) {
        $exp[$i] = $exp[$i - 255];
    }
    return [$exp, $log];
}

function tf_qr_rs(array $data, $ecLen) {
    [$exp, $log] = tf_qr_gf();
    $gen = [1];
    for ($i = 0; $i < $ecLen; $i++) {
        $next = array_fill(0, count($gen) + 1, 0);
        $factor = $exp[$i];
        for ($j = 0; $j < count($gen); $j++) {
            $next[$j] ^= $gen[$j];
            $next[$j + 1] ^= ($gen[$j] === 0) ? 0 : $exp[($log[$gen[$j]] + $log[$factor]) % 255];
        }
        $gen = $next;
    }
    $ecc = array_fill(0, $ecLen, 0);
    foreach ($data as $byte) {
        $factor = $byte ^ $ecc[0];
        array_shift($ecc);
        $ecc[] = 0;
        if ($factor === 0) continue;
        $lf = $log[$factor];
        for ($i = 0; $i < $ecLen; $i++) {
            if ($gen[$i + 1] === 0) continue;
            $ecc[$i] ^= $exp[($lf + $log[$gen[$i + 1]]) % 255];
        }
    }
    return $ecc;
}

function tf_qr_reserved($size, $version) {
    $r = array_fill(0, $size, array_fill(0, $size, false));
    $markFinder = function ($x0, $y0) use (&$r) {
        for ($y = -1; $y <= 7; $y++) {
            for ($x = -1; $x <= 7; $x++) {
                $xx = $x0 + $x;
                $yy = $y0 + $y;
                if ($xx < 0 || $yy < 0 || $xx >= count($r) || $yy >= count($r)) continue;
                $r[$yy][$xx] = true;
            }
        }
    };
    $markFinder(0, 0);
    $markFinder($size - 7, 0);
    $markFinder(0, $size - 7);

    $align = [1 => [], 2 => [18], 3 => [22], 4 => [26], 5 => [30], 6 => [34]];
    foreach ($align[$version] as $ax) {
        foreach ($align[$version] as $ay) {
            if (($ax <= 8 && $ay <= 8) || ($ax >= $size - 9 && $ay <= 8) || ($ax <= 8 && $ay >= $size - 9)) continue;
            for ($y = -2; $y <= 2; $y++) {
                for ($x = -2; $x <= 2; $x++) {
                    $r[$ay + $y][$ax + $x] = true;
                }
            }
        }
    }

    for ($i = 0; $i < $size; $i++) {
        $r[6][$i] = true;
        $r[$i][6] = true;
    }
    for ($i = 0; $i < 9; $i++) {
        $r[8][$i] = true;
        $r[$i][8] = true;
        $r[8][$size - 1 - $i] = true;
        $r[$size - 1 - $i][8] = true;
    }
    $r[$size - 8][8] = true;
    return $r;
}

function tf_qr_place($size, $version, $bits, $reserved, $mask) {
    $grid = array_fill(0, $size, array_fill(0, $size, 0));

    $drawFinder = function ($x0, $y0) use (&$grid) {
        for ($y = 0; $y < 7; $y++) {
            for ($x = 0; $x < 7; $x++) {
                $on = ($x === 0 || $x === 6 || $y === 0 || $y === 6 || ($x >= 2 && $x <= 4 && $y >= 2 && $y <= 4));
                $grid[$y0 + $y][$x0 + $x] = $on ? 1 : 0;
            }
        }
    };
    $drawFinder(0, 0);
    $drawFinder($size - 7, 0);
    $drawFinder(0, $size - 7);

    $align = [1 => [], 2 => [18], 3 => [22], 4 => [26], 5 => [30], 6 => [34]];
    foreach ($align[$version] as $ax) {
        foreach ($align[$version] as $ay) {
            if (($ax <= 8 && $ay <= 8) || ($ax >= $size - 9 && $ay <= 8) || ($ax <= 8 && $ay >= $size - 9)) continue;
            for ($y = -2; $y <= 2; $y++) {
                for ($x = -2; $x <= 2; $x++) {
                    $on = (abs($x) === 2 || abs($y) === 2 || ($x === 0 && $y === 0));
                    $grid[$ay + $y][$ax + $x] = $on ? 1 : 0;
                }
            }
        }
    }

    for ($i = 0; $i < $size; $i++) {
        $grid[6][$i] = ($i % 2 === 0) ? 1 : 0;
        $grid[$i][6] = ($i % 2 === 0) ? 1 : 0;
    }
    $grid[$size - 8][8] = 1;

    $bitIndex = 0;
    $len = strlen($bits);
    $dir = -1;
    $col = $size - 1;
    while ($col > 0) {
        if ($col === 6) $col--;
        for ($i = 0; $i < $size; $i++) {
            $y = ($dir < 0) ? ($size - 1 - $i) : $i;
            for ($dx = 0; $dx < 2; $dx++) {
                $x = $col - $dx;
                if ($reserved[$y][$x]) continue;
                $bit = ($bitIndex < $len) ? ($bits[$bitIndex] === '1') : false;
                $bitIndex++;
                if (tf_qr_mask($mask, $x, $y)) $bit = !$bit;
                $grid[$y][$x] = $bit ? 1 : 0;
            }
        }
        $dir = -$dir;
        $col -= 2;
    }

    // Format info: ECC M = 00
    $format = tf_qr_format_bits(0, $mask);
    $positions = [
        [8, 0], [8, 1], [8, 2], [8, 3], [8, 4], [8, 5], [8, 7], [8, 8],
        [7, 8], [5, 8], [4, 8], [3, 8], [2, 8], [1, 8], [0, 8],
    ];
    $positions2 = [
        [$size - 1, 8], [$size - 2, 8], [$size - 3, 8], [$size - 4, 8], [$size - 5, 8], [$size - 6, 8], [$size - 7, 8],
        [8, $size - 8], [8, $size - 7], [8, $size - 6], [8, $size - 5], [8, $size - 4], [8, $size - 3], [8, $size - 2], [8, $size - 1],
    ];
    for ($i = 0; $i < 15; $i++) {
        $bit = ($format >> (14 - $i)) & 1;
        $grid[$positions[$i][0]][$positions[$i][1]] = $bit;
        $grid[$positions2[$i][0]][$positions2[$i][1]] = $bit;
    }
    return $grid;
}

function tf_qr_mask($mask, $x, $y) {
    switch ($mask) {
        case 0: return (($x + $y) % 2) === 0;
        case 1: return ($y % 2) === 0;
        case 2: return ($x % 3) === 0;
        case 3: return (($x + $y) % 3) === 0;
        case 4: return ((intdiv($y, 2) + intdiv($x, 3)) % 2) === 0;
        case 5: return (($x * $y) % 2) + (($x * $y) % 3) === 0;
        case 6: return (((($x * $y) % 2) + (($x * $y) % 3)) % 2) === 0;
        case 7: return (((($x + $y) % 2) + (($x * $y) % 3)) % 2) === 0;
    }
    return false;
}

function tf_qr_format_bits($ecl, $mask) {
    // ecl 0 = M. Precomputed BCH format values (ISO/IEC 18004).
    $table = [
        0 => [0x5412, 0x5125, 0x5E7C, 0x5B4B, 0x45F9, 0x40CE, 0x4F97, 0x4AA0],
    ];
    return $table[$ecl][$mask];
}

function tf_qr_penalty($grid) {
    $n = count($grid);
    $score = 0;
    for ($y = 0; $y < $n; $y++) {
        $run = 1;
        for ($x = 1; $x < $n; $x++) {
            if ($grid[$y][$x] === $grid[$y][$x - 1]) {
                $run++;
                if ($run === 5) $score += 3;
                elseif ($run > 5) $score++;
            } else {
                $run = 1;
            }
        }
    }
    for ($x = 0; $x < $n; $x++) {
        $run = 1;
        for ($y = 1; $y < $n; $y++) {
            if ($grid[$y][$x] === $grid[$y - 1][$x]) {
                $run++;
                if ($run === 5) $score += 3;
                elseif ($run > 5) $score++;
            } else {
                $run = 1;
            }
        }
    }
    for ($y = 0; $y < $n - 1; $y++) {
        for ($x = 0; $x < $n - 1; $x++) {
            $v = $grid[$y][$x];
            if ($v === $grid[$y][$x + 1] && $v === $grid[$y + 1][$x] && $v === $grid[$y + 1][$x + 1]) {
                $score += 3;
            }
        }
    }
    $dark = 0;
    for ($y = 0; $y < $n; $y++) {
        for ($x = 0; $x < $n; $x++) {
            $dark += $grid[$y][$x];
        }
    }
    $score += (int)(abs((100 * $dark / ($n * $n)) - 50) / 5) * 10;
    return $score;
}
