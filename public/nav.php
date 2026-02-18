<header><nav>
<a href="index.php">Dashboard</a>
<a href="product_setup.php">Product Setup</a>
<a href="planning.php">Production Planning</a>
<a href="task_board.php">Live Task Board</a>
<a href="update_task.php">Update Task</a>
<a href="override.php">Manager Override</a>
<a href="install.php">Installer</a>
</nav></header>
<div id="db-alert" class="db-alert" style="display:none"></div>
<script>
(async function(){
  try {
    const res = await fetch('/api.php?action=health');
    const data = await res.json();
    if(!res.ok || !data.ok){
      const el = document.getElementById('db-alert');
      el.style.display = 'block';
      el.textContent = (data && data.error) ? data.error : 'Database unavailable. Please complete Installer setup.';
    }
  } catch (_e) {
    const el = document.getElementById('db-alert');
    el.style.display = 'block';
    el.textContent = 'Database unavailable. Please complete Installer setup.';
  }
})();
</script>
