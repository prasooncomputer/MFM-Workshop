<?php include 'config.php'; ?>
<!doctype html>
<html><head><title>Workshop Board</title><link rel="stylesheet" href="style.css"></head>
<body>
<?php include 'nav.php'; ?>
<div class="container">
  <div class="card"><h2>Workshop Production Planning & Management</h2><p>Authoritative live board for supervisors and workers.</p></div>
  <div class="grid grid-2">
    <div class="card"><h3>System Summary</h3><div id="summary">Loading...</div></div>
    <div class="card"><h3>Worker Productivity</h3><div id="worker">Loading...</div></div>
  </div>
</div>
<script>
const api='<?= $apiBase ?>';
async function load(){
  const data=await (await fetch(`${api}?action=dashboard_summary`)).json();
  document.getElementById('summary').innerHTML=`<p>Today's Tasks: ${data.today.today_tasks||0}</p><p>Blocked: ${data.today.blocked||0}</p><p>Risk: ${data.today.risk||0}</p><p>Expected Dispatch: ${data.expected_dispatch||'N/A'}</p>`;
  document.getElementById('worker').innerHTML=(data.worker_productivity||[]).map(w=>`<p>${w.employee_name}: ${w.productivity||0} units/hr</p>`).join('')||'No logs yet';
}
load();
setInterval(load,30000);
</script>
</body></html>
