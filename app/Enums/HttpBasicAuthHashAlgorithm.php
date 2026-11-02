<?php

namespace App\Enums;

enum HttpBasicAuthHashAlgorithm: string
{
    case BCRYPT = 'bcrypt';
    case ARGON2ID = 'argon2id';
}
