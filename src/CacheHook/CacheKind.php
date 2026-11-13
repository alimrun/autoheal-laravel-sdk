<?php

namespace AutoHeal\Laravel\CacheHook;

enum CacheKind: string
{
    case VIEWS = 'views';
    case STORE = 'store';
}
