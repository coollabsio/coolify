$handle = fopen("/tmp/export.csv", "w");
App\Models\Team::chunk(100, function ($teams) use ($handle) {
  foreach ($teams as $team) {
    if ($team->subscription->stripe_invoice_paid == true) {
      foreach ($team->members as $member) {
        fputcsv($handle, [$member->email, $member->name], ",");
      }
    }
  }
});
fclose($handle);
