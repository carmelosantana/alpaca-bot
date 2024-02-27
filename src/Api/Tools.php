<?php

declare(strict_types=1);

namespace CarmeloSantana\AlpacaBot\Api;

use CarmeloSantana\AlpacaBot\Utils\Options;

class Tools
{
    public static function addAuth($args)
    {
        $username = Options::get('api_username');
        $password = Options::get('api_password');

        if ($username and $password) {
            if (!isset($args['headers'])) {
                $args['headers'] = [];
            }
            $args['headers']['Authorization'] = 'Basic ' . base64_encode($username . ':' . $password);
        }
        
        return $args;
    }
}
