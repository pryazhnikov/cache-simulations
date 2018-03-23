#!/usr/bin/env php
<?php

const DEFAULT_VERBOSE_MODE = false;
const DEFAULT_KEYS_COUNT = 1e6;
const DEFAULT_SHARDS_COUNT_RANGE = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10];


interface IHashingAlgorithm
{
    public function getKeyShard(string $key, int $shards_count) : int;
}


class ModuloHashing implements IHashingAlgorithm
{
    private $keyHashes = [];

    public function getKeyShard(string $key, int $shards_count) : int
    {
        return $this->getKeyHash($key) % $shards_count;
    }

    private function getKeyHash(string $key) : int
    {
        if (!isset($this->keyHashes[$key])) {
            $this->keyHashes[$key] = $this->calculateKeyHash($key);
        }

        return $this->keyHashes[$key];
    }

    private function calculateKeyHash(string $key) : int
    {
        return crc32($key);
    }
}


function getLostKeysStats(IHashingAlgorithm $HashAlgo, $total_keys_count, $shards_count_before, $shards_count_after) : iterable
{
    $lost_keys_count = 0;
    for ($i = 0; $i < $total_keys_count; $i++) {
        $key = "user:{$i}";
        $key_shard_before = $HashAlgo->getKeyShard($key, $shards_count_before);
        $key_shard_after  = $HashAlgo->getKeyShard($key, $shards_count_after);
        
        if ($key_shard_before != $key_shard_after) {
            $lost_keys_count++;
        }
    }

    $lost_keys_percent = 100 * $lost_keys_count / $total_keys_count;
    return [$lost_keys_count, $lost_keys_percent];
}


// @todo Сделать получение значений из argv
$total_keys_count = DEFAULT_KEYS_COUNT;
$is_verbose_mode = (bool)DEFAULT_VERBOSE_MODE;

$shards_count_range = DEFAULT_SHARDS_COUNT_RANGE;
$shards_count_range = array_unique(array_map('intval', $shards_count_range));
sort($shards_count_range, SORT_NUMERIC);

if ($is_verbose_mode) {
    print("Total keys count:\t{$total_keys_count}\n");
    printf("Shards count range:\t%s\n\n", join(', ', $shards_count_range));
}

// Старт прогона
$HashingAlgo = new \ModuloHashing();
$is_header_shown = false;
foreach ($shards_count_range as $shards_count_before) {
    $line_data = [$shards_count_before];
    foreach ($shards_count_range as $shards_count_after) {
        [$modulo_lost_keys_count, $modulo_lost_keys_percent] = getLostKeysStats(
            $HashingAlgo,
            $total_keys_count,
            $shards_count_before,
            $shards_count_after
        );
        
        $item = [
            'ShardsBefore'    => $shards_count_before,
            'ShardsAfter'     => $shards_count_after,
            'LostKeysPercent' => sprintf('%05.2f%%', $modulo_lost_keys_percent),
        ];
        if ($is_verbose_mode) {
            $item['LostKeys'] = $modulo_lost_keys_count;
        }

        if (!$is_header_shown) {
            $is_header_shown = true;
            print join("\t", array_keys($item)) . PHP_EOL;
        }

        print join("\t", array_values($item)) . PHP_EOL;
    }
}
