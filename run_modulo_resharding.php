#!/usr/bin/env php
<?php
/**
 * A script for calculating the cache loss on the servers count change.
 *
 * How it works:
 *
 * The input has the number of keys and a set of values for the servers count.
 * The pairs of servers count are generated using the input set. Each pair consist of
 * the number of servers before the change, and the number of servers after the change.
 * Using the remainder of dividing the hash by the number of servers for each key
 * the server index is evaluated for the server counts at the pair. If the server indexes are different,
 * we treat this key as a lost one.
 *
 * The percentage of cache losses for each pair of servers is written to STDOUT.
 */

/**
 * Количество ключей в кэше
 */
const DEFAULT_KEYS_COUNT = 1e6;
/**
 * Количество серверов для анализа
 */
const DEFAULT_SHARDS_COUNT_RANGE = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10];


/**
 * Разбирает значение аргумента со списком серверов
 *
 * @param string $str Значение
 *
 * @return array Список количества серверов
 */
function parseServersArgument(string $str) : array
{
    $str = trim($str);

    // "1-10"
    if (preg_match('/^(\d+)-(\d+)$/', $str, $match)) {
        $min_value = intval($match[1]);
        $max_value = intval($match[2]);
        return range($min_value, $max_value);
    }

    // "1,2,3" / "1;2;3" / "1 2 3"
    return preg_split('/[\s,;]+/', $str);
}


/**
 * Интерфейс для выбора сервера на базе значения ключа
 */
interface IHashingAlgorithm
{
    /**
     * @param string $key          ключ кэширования
     * @param int    $shards_count доступное количество серверов
     *
     * @return int индекс сервера, выбранного для ключа
     */
    public function getKeyShard(string $key, int $shards_count) : int;
}


/**
 * Реализаци выбора сервера на базе значения ключа с помощью остатка от деления
 */
class ModuloHashing implements IHashingAlgorithm
{
    private $key_hashes = [];

    /**
     * {@inheritdoc }
     */
    public function getKeyShard(string $key, int $shards_count) : int
    {
        return $this->getKeyHash($key) % $shards_count;
    }

    private function getKeyHash(string $key) : int
    {
        if (!isset($this->key_hashes[$key])) {
            $this->key_hashes[$key] = $this->calculateKeyHash($key);
        }

        return $this->key_hashes[$key];
    }

    private function calculateKeyHash(string $key) : int
    {
        return crc32($key);
    }
}


/**
 * Вычисляет процент потерь для заданной пары серверов
 *
 * @param IHashingAlgorithm $HashAlgo            реализаци алгоритм выбора сервера
 * @param int               $total_keys_count    общее количество ключей для проверки
 * @param int               $shards_count_before количиство серверов до изменения
 * @param int               $shards_count_after  количиство серверов после изменения
 *
 * @return array            массив из двух элементов: общее число потерянных ключей и
 *                          процент потерянных ключей
 */
function getLostKeysStats(
    IHashingAlgorithm $HashAlgo,
    $total_keys_count,
    $shards_count_before,
    $shards_count_after
) : array {
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

// Инициализация параметров прогона
$argv_options = getopt(
    '',
    [
        "keys::",    // Количество ключей в кэше
        "servers::", // Варианты для количества серверов
        "verbose",   // Подробный вывод?
    ]
);

$total_keys_count = $argv_options['keys'] ?? DEFAULT_KEYS_COUNT;
$is_verbose_mode = isset($argv_options['verbose']);

if (empty($argv_options['servers'])) {
    $shards_count_range = DEFAULT_SHARDS_COUNT_RANGE;
} else {
    $shards_count_range = parseServersArgument($argv_options['servers']);
    if (!$shards_count_range) {
        print "Cannot parse servers argument value: '{$argv_options['servers']}'";
        exit(1);
    }
}

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
