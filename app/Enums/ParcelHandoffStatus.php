<?php

namespace App\Enums;

enum ParcelHandoffStatus: string
{
    case Requested = 'REQUESTED';
    case Accepted = 'ACCEPTED';
    case Rejected = 'REJECTED';
    case InTransit = 'IN_TRANSIT';
    case Received = 'RECEIVED';
}
