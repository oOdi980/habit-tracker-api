<?php

namespace App\Enums;

enum EvaluationResult: string
{
    case Reward = 'reward';
    case Punishment = 'punishment';
    case Neutral = 'neutral';
}
