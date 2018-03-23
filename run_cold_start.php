#!/usr/bin/env php
<?php

const DEFAULT_NUMBER_OF_USERS = 10000;
const DEFAULT_REQUESTS_PER_SECONDS = 5000;
const DEFAULT_CACHE_TIME_TO_LIVE_SECONDS = 10;
const DEFAULT_SIMULATION_TIME_SECONDS = 100;
const DEFAULT_USE_RANDOM_TTL = true;


// Инициализация генератора случайных чисел для того,
// чтобы получить воспроизводимый результат прогона
mt_srand(1);


function getRandomCacheItemTTL(int $fixed_item_ttl, int $request_user_id) : int
{
    // Используем не случайное значение, а псевдослучайный множитель для того, чтобы
    // порядок запросов, выбираемый с помощью генератора случайных чисел, был один и тот же
    // как в прогоне с фиксированным временем жизни, так и в прогоне случая со "случайным".
    $factor = (7 * $request_user_id) % 4 + 8; // => [8, 11]
    return round($fixed_item_ttl * $factor / 10); // 
}


function getPeriodHash(array $period_user_ids) : string
{
    $period_str = join("\t", $period_user_ids);
    return md5($period_str);
}


// @todo сделать получение параметров из argv
$simulation_time_seconds = DEFAULT_SIMULATION_TIME_SECONDS;
$number_of_users         = DEFAULT_NUMBER_OF_USERS;
$requests_per_second     = DEFAULT_REQUESTS_PER_SECONDS;
$fixed_cache_ttl         = DEFAULT_CACHE_TIME_TO_LIVE_SECONDS;
$use_random_ttl          = DEFAULT_USE_RANDOM_TTL;
$is_verbose_mode         = true;

if ($is_verbose_mode) {
    $ttl_mode_name = use_random_ttl ? 'Random' : 'Fixed';

    print "Simulation time, sec: {$simulation_time_seconds}\n";
    print "Unique users count:   {$number_of_users}\n";
    print "Requests per second:  {$requests_per_second}\n";
    print "Fixed TTL, seconds:   {$fixed_cache_ttl}\n";
    print "TTL mode: {$ttl_mode_name}\n";
    print "\n";
}

$is_header_shown = false;
$cached_items = [];
for ($time = 0; $time < $simulation_time_seconds; $time++) {
    // Инициализация статистики на новый момент времени
    $period_requests     = 0;
    $period_cache_misses = 0;
    $period_cache_hits   = 0;
    $period_user_ids     = [];

    for ($request_id = 0; $request_id < $requests_per_second; $request_id++) {
        $period_requests++;

        $request_user_id = mt_rand(0, $number_of_users);
        $is_cache_hit = isset($cached_items[$request_user_id])
            && ($time <= $cached_items[$request_user_id]);
        if ($is_cache_hit) {
            $period_cache_hits++;
        } else {
            $period_cache_misses++;
            if ($use_random_ttl) {
                $item_ttl = getRandomCacheItemTTL($fixed_cache_ttl, $request_user_id);
            } else {
                $item_ttl = $fixed_cache_ttl;
            }
            
            $cache_expire_time = $time + $item_ttl;
            $cached_items[$request_user_id] = $cache_expire_time;
        }

        if ($is_verbose_mode) {
            $period_user_ids[] = $request_user_id;
        }
    }

    $miss_percent = 100 * $period_cache_misses / $period_requests;
    $item = [
        'time'                => $time,
        'miss_percent'        => $miss_percent, 
        'period_cache_misses' => $period_cache_misses,
        'period_requests'     => $period_requests,
    ];
    if ($is_verbose_mode) {
        // Порядок запросов не должен зависить от значения $use_random_ttl
        // Хеш нужен для проверки этого порядка.
        $item['period_hash'] = getPeriodHash($period_user_ids);
    }

    if (!$is_header_shown) {
        $is_header_shown = true;
        print join("\t", array_keys($item)) . PHP_EOL;
    }

    print join("\t", array_values($item)) . PHP_EOL;
}
