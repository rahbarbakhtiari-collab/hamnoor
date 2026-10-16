# Reward Limit Contract v2

v2 extends the verified v1 contract without changing storage schema.

New optional fields:

```json
{
  "version": 2,
  "max_awards_per_month": 20,
  "max_points_per_month": 400
}
```

Rules with v1 JSON remain valid. Missing monthly fields mean no monthly cap.

Calendar-month boundaries are calculated in the configured Reward Policy Timezone while database timestamps remain UTC. As with daily limits, the account row lock must already be held during evaluation, Ledger rows are the successful-award source of truth, and partial awards are never issued.

New decision codes:

- `limit.monthly_awards`
- `limit.monthly_points`
