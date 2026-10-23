<?php
/**
 * LOCAL-ONLY configuration overrides (development machine).
 * NOT uploaded to production — remove or ignore on Hostinger.
 */
$CFG['db']['driver'] = 'sqlite';
$CFG['db']['file']   = __DIR__ . '/database/wildboar_gym.sqlite';
$CFG['app_url']      = 'http://localhost:8090';