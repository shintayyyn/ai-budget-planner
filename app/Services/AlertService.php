<?php

namespace App\Services;

use App\Support\Money;
use App\Models\Alert;
use App\Models\SharedPlanInvite;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Re-evaluates a user's finances and raises spending alerts. Each alert has a
 * stable key, so the same warning is never raised twice; unread alerts whose
 * condition has cleared are removed.
 */
class AlertService
{
    public function __construct(private FinanceService $finance) {}

    public function refresh(User $user): void
    {
        $today = Carbon::today();
        $month = $this->finance->monthSummary($user);
        $sts = $this->finance->safeToSpend($user);
        $threshold = $user->alert_threshold ?: 80;
        $fresh = [];

        foreach ($month['categories'] as $c) {
            if (! $c['budget']) {
                continue;
            }
            if ($c['spent'] > $c['budget'] + 0.009) {
                $fresh["over:{$month['month']}:{$c['id']}"] = ['danger', "{$c['icon']} {$c['name']} is over budget",
                    sprintf('You have spent %s of your %s %s budget (%s over).', $this->m($user, $c['spent']), $this->m($user, $c['budget']), $c['name'], $this->m($user, $c['spent'] - $c['budget']))];
            } elseif ($c['percent'] >= $threshold && $c['spent'] - $c['bill_spent'] > 0.009) {
                $daysLeft = $month['days_in_month'] - $month['days_elapsed'];
                $fresh["near:{$month['month']}:{$c['id']}"] = ['warning', "{$c['icon']} {$c['name']} at {$c['percent']}%",
                    sprintf('Only %s left for %s with %d days to go in the month.', $this->m($user, $c['remaining']), $c['name'], $daysLeft)];
            }
        }

        if ($month['total_budget'] > 0 && $month['days_elapsed'] >= 5 && $month['projected_spend'] > $month['total_budget'] * 1.05) {
            $fresh["pace:{$month['month']}"] = ['warning', 'Spending pace is too high',
                sprintf('At this pace you will spend %s this month, against a budget of %s.', $this->m($user, $month['projected_spend']), $this->m($user, $month['total_budget']))];
        }

        if ($sts['run_out_date']) {
            $fresh["runout:{$sts['next_payday']}"] = ['danger', 'Risk of running out before payday',
                sprintf('At about %s/day, your money runs out around %s, before payday on %s. Try to keep to %s/day.',
                    $this->m($user, $sts['daily_spend_rate']), Carbon::parse($sts['run_out_date'])->format('M j'),
                    Carbon::parse($sts['next_payday'])->format('M j'), $this->m($user, max(0, $sts['daily_allowance'])))];
        } elseif ($sts['safe_to_spend'] < 0) {
            $fresh["short:{$sts['next_payday']}"] = ['danger', 'Bills exceed your balance',
                sprintf('Upcoming bills and savings (%s) are more than your balance before payday.', $this->m($user, $sts['bills_total'] + $sts['savings_reserved']))];
        }

        foreach ($sts['bills_due'] as $bill) {
            $due = Carbon::parse($bill['due']);
            if ($today->diffInDays($due, false) <= 3) {
                $fresh["bill:{$bill['id']}:{$bill['due']}"] = ['info', "{$bill['name']} due ".($due->isToday() ? 'today' : $due->format('M j')),
                    sprintf('%s is due. Mark it paid once it is done so your plan stays accurate.', $this->m($user, $bill['amount']))];
            }
        }

        $invites = SharedPlanInvite::with(['plan:id,name,icon,visibility', 'inviter:id,name'])
            ->where('email', strtolower($user->email))->where('status', 'pending')->get()
            ->filter(fn ($i) => $i->plan?->visibility === 'group');
        foreach ($invites as $invite) {
            $fresh["invite:{$invite->id}"] = ['info', "{$invite->plan->icon} Invitation: {$invite->plan->name}",
                "{$invite->inviter->name} invited you to plan and save together. Open Goals → Together to accept."];
        }

        foreach ($fresh as $key => [$level, $title, $message]) {
            $alert = $user->alerts()->firstOrNew(['key' => $key]);
            $alert->fill(['level' => $level, 'title' => $title, 'message' => $message])->save();
        }

        $user->alerts()->whereNull('read_at')->whereNotIn('key', array_keys($fresh) ?: [''])->delete();
    }

    private function m(User $user, float $amount): string
    {
        return Money::format($amount, $user->currency);
    }
}
