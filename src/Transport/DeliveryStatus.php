<?php

namespace AutoHeal\Laravel\Transport;

enum DeliveryStatus: string
{
    case ACCEPTED = 'accepted';
    case QUEUED = 'queued';
    case REFUSED = 'refused';
    case REJECTED = 'rejected';
    case UNREACHABLE = 'unreachable';
    case PAUSED = 'paused';
    case DISABLED = 'disabled';
}
