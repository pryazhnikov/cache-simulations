#!/usr/bin/env php
<?php
/**
 * Скрипт для расчёта потерь кэша при изменении количества серверов.
 *
 * Принцип работы:
 *
 * На входе есть количество ключей и набор значений для количества серверов.
 * Из количества серверов генерируются пары, один элемент которой
 * считается количестваом серверов до изменения, а второй - после изменения.
 * С помощью остатка от деления хеша на количество серверов для каждого ключа
 * определяется индекс сервера до изменения и после. Если они не совспадают,
 * то мы считаем, что ключ потерян.
 *
 * В STDOUT пишется процент потерь кэша для каждой пары количества серверов.
 */

/**
 * Использовать полее подробный вывод.
 * Если этот режим включен, то вывод нельзя будет использовать как CSV!
 */
const DEFAULT_VERBOSE_MODE = false;
/**
 * Количество ключей в кэше
 */
const DEFAULT_KEYS_COUNT = 1e6;
/**
 * Количество серверов для анализа
 */
const DEFAULT_SHARDS_COUNT_RANGE = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10];


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
 * Вычиссляет процент потерь для заданной пары серверов
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
