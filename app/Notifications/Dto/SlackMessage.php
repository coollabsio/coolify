<?php

namespace App\Notifications\Dto;

class SlackMessage
{
    public function __construct(
        public string $title,
        public string $description,
        public string $color = '#0099ff'
    ) {}

    public static function infoColor(): string
    {
        return '#0099ff';
    }

    public static function errorColor(): string
    {
        return '#ff0000';
    }

    public static function successColor(): string
    {
        return '#00ff00';
    }

    public static function warningColor(): string
    {
        return '#ffa500';
    }
}
