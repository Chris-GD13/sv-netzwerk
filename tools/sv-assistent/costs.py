"""Reproducible planning estimates, not a billing meter or API integration."""
import json
from decimal import Decimal, ROUND_CEILING, ROUND_HALF_UP
from pathlib import Path


def estimate(scenario, models, factors):
    total = Decimal(0)
    for call in scenario['calls']:
        rate = models[call['model']]
        for side in ('input', 'output'):
            tokens = call[side+'_tokens']
            if type(tokens) is not int or tokens < 0:
                raise ValueError('Token counts must be non-negative integers')
            total += Decimal(tokens) * Decimal(str(rate[side])) / Decimal(1000000)
    planned = total
    for value in factors.values():
        factor = Decimal(str(value))
        if not factor.is_finite() or factor < 1:
            raise ValueError('Planning factors must be finite and >= 1')
        planned *= factor
    return {'usd_base': str(total), 'eur_planning': str(planned),
            'eur_reserve_cents': int((planned*100).to_integral_value(rounding=ROUND_CEILING)),
            'monthly_eur': {str(n): str((planned*n).quantize(Decimal('.01'), rounding=ROUND_HALF_UP))
                            for n in (10, 50, 100)}}


def calculate(plan):
    return {name: estimate(value, plan['models'], plan['factors'])
            for name, value in plan['scenarios'].items()}


if __name__ == '__main__':
    plan = json.loads(Path(__file__).with_name('planning.json').read_text(encoding='utf-8'))
    print(json.dumps(calculate(plan), indent=2))
