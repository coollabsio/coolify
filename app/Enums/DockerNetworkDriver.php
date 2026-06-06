<?php

namespace App\Enums;

enum DockerNetworkDriver: string
{
    case Bridge = 'bridge';
    case Overlay = 'overlay';
    case Macvlan = 'macvlan';
    case Ipvlan = 'ipvlan';
    case Host = 'host';
    case None = 'none';
    case Unknown = 'unknown';
}
