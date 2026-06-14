<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Analytics — Yummy Soda Admin</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="stylesheet" href="/yummy-soda/admin/admin.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <style>
    .chart-tabs {
      display: flex;
      gap: 6px;
      padding: 16px 24px;
      border-bottom: 1px solid var(--border);
      background: #f8fafc;
      flex-wrap: wrap;
    }
    .chart-tab {
      padding: 7px 16px;
      border-radius: 99px;
      border: 1.5px solid var(--border);
      background: var(--surface);
      font-size: 12.5px;
      font-weight: 700;
      color: var(--text-muted);
      cursor: pointer;
      transition: all var(--transition);
      font-family: var(--font);
    }
    .chart-tab:hover, .chart-tab.active {
      background: var(--teal);
      border-color: var(--teal);
      color: #fff;
    }
    .filter-row {
      display: flex;
      align-items: flex-end;
      gap: 12px;
      flex-wrap: wrap;
    }
    .filter-group {
      display: flex;
      flex-direction: column;
      gap: 5px;
    }
    .chart-canvas-wrap {
      position: relative;
      height: 320px;
      padding: 24px;
    }
  .btn-glow-teal {
    box-shadow: 0 0 12px rgba(34,193,195,0.5), 0 4px 15px rgba(34,193,195,0.3);
    transition: all 0.25s ease;
  }
  .btn-glow-teal:hover {
    box-shadow: 0 0 20px rgba(34,193,195,0.75), 0 6px 20px rgba(34,193,195,0.45);
    transform: translateY(-1px);
  }
  .btn-glow-amber {
    box-shadow: 0 0 12px rgba(253,187,45,0.5), 0 4px 15px rgba(253,187,45,0.3);
    transition: all 0.25s ease;
  }
  .btn-glow-amber:hover {
    box-shadow: 0 0 20px rgba(253,187,45,0.75), 0 6px 20px rgba(253,187,45,0.45);
    transform: translateY(-1px);
  }
  </style>
</head>
<body>

<?php
// For nav badge — analytics.php has no PHP session logic, but we open DB for badge
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/db.php';
require_admin();
$pdo = db();
$currentPage = 'analytics';
require __DIR__ . '/_nav.php';
?>

<div class="main">
  <header class="topbar">
    <div class="topbar-title">
      <div class="topbar-title-icon">📊</div>
      Analytics
    </div>
    <div class="topbar-actions">
      <a href="/yummy-soda/api/export_csv.php" class="topbar-btn">📄 Export CSV</a>
      <a href="/yummy-soda/api/export_excel.php" class="topbar-btn">📑 Export Excel</a>
      <a href="/yummy-soda/api/export_pdf.php" class="topbar-btn">🖨️ Export PDF</a>
      <a href="/yummy-soda/admin/etl_sync.php" class="topbar-btn primary">⚡ Sync ETL</a>
    </div>
  </header>

  <div class="page-content">

    <!-- Monthly Roll-up -->
    <div class="panel">
      <div class="panel-header">
        <div class="panel-title">
          <span class="panel-title-dot"></span>
          Monthly Revenue Roll-up
        </div>
        <div style="font-size:12px;color:var(--text-muted);">Last 12 months</div>
      </div>
      <div class="chart-canvas-wrap">
        <canvas id="monthlyChart"></canvas>
      </div>
    </div>

    <!-- Daily Drill-down -->
    <div class="panel">
      <div class="panel-header">
        <div class="panel-title">
          <span class="panel-title-dot" style="background:var(--amber);"></span>
          Daily Drill-down
        </div>
      </div>
      <div class="filter-bar">
        <div class="filter-row">
          <div class="filter-group">
            <label>Pick Month</label>
            <input type="month" id="ym" value="<?=date('Y-m')?>"
              style="padding:9px 14px;border:1.5px solid var(--border);border-radius:var(--radius-sm);font-family:var(--font);font-size:13.5px;">
          </div>
          <button class="btn btn-primary" id="drillBtn">Load Days</button>
        </div>
      </div>
      <div class="chart-canvas-wrap">
        <canvas id="dailyChart"></canvas>
      </div>
    </div>

    <!-- Slice by Payment -->
    <div class="panel">
      <div class="panel-header">
        <div class="panel-title">
          <span class="panel-title-dot" style="background:var(--blue);"></span>
          Revenue by Payment Method
        </div>
      </div>
      <div class="filter-bar">
        <div class="filter-row">
          <div class="filter-group">
            <label>Payment Method</label>
            <select id="method" style="padding:9px 14px;border:1.5px solid var(--border);border-radius:var(--radius-sm);font-family:var(--font);font-size:13.5px;">
              <option value="CASH">CASH</option>
              <option value="GCASH">GCASH</option>
              <option value="CARD">CARD</option>
            </select>
          </div>
          <button class="btn btn-primary" id="sliceBtn">Load Slice</button>
        </div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;padding:24px;">
        <div style="position:relative;height:300px;display:flex;align-items:center;justify-content:center;">
          <canvas id="sliceChart"></canvas>
        </div>
        <div id="sliceLegend" style="display:flex;flex-direction:column;justify-content:center;gap:10px;"></div>
      </div>
    </div>

    <!-- Dice Export -->
    <div class="panel">
      <div class="panel-header">
        <div class="panel-title">
          <span class="panel-title-dot" style="background:var(--green);"></span>
          Dice — Custom Date Range Export
        </div>
      </div>
      <div class="panel-body">
        <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
          <div class="filter-group">
            <label>From</label>
            <input id="dice_from" type="date" value="<?=date('Y-m-01')?>">
          </div>
          <div class="filter-group">
            <label>To</label>
            <input id="dice_to" type="date" value="<?=date('Y-m-d')?>">
          </div>
          <div class="filter-group">
            <label>Category (optional)</label>
            <input id="dice_category" placeholder="e.g. Soda">
          </div>
          <div class="filter-group">
            <label>Payment Method</label>
            <select id="dice_method">
              <option value="">Any method</option>
              <option value="CASH">CASH</option>
              <option value="GCASH">GCASH</option>
              <option value="CARD">CARD</option>
            </select>
          </div>
          <div style="display:flex;gap:8px;">
            <button type="button" class="btn btn-primary btn-glow-teal" onclick="diceExport('csv')">📄 Export CSV</button>
            <button type="button" class="btn btn-primary btn-glow-amber" onclick="diceExport('excel')" style="background:var(--amber);border-color:var(--amber);">📑 Export Excel</button>
            <button type="button" class="btn btn-primary" onclick="diceExport('pdf')" style="background:#ef4444;border-color:#ef4444;">🖨️ Export PDF</button>
          </div>
        </div>
      </div>
    </div>

  </div><!-- /page-content -->
</div><!-- /main -->

<script>
const fetchJson = async (url) => {
  try {
    const r = await fetch(url, { credentials: 'include' });
    if (!r.ok) throw new Error('HTTP ' + r.status);
    const text = await r.text();
    if (!text.trim()) return [];
    return JSON.parse(text);
  } catch (e) {
    console.warn('fetchJson failed:', url, e.message);
    return [];
  }
};

const COLORS = ['#22C1C3','#fdbb2d','#22c55e','#f97316','#8b5cf6','#ec4899','#3b82f6','#14b8a6'];

Chart.defaults.font.family = "'DM Sans', sans-serif";
Chart.defaults.color = '#6b7280';

let monthlyChart, dailyChart, sliceChart;

const loadMonthly = async () => {
  const data = await fetchJson('/yummy-soda/api/analytics_data.php?op=rollup_month');
  const labels = data.map(x => `${x.year}-${String(x.month).padStart(2,'0')}`);
  const values = data.map(x => Number(x.revenue));
  const ctx = document.getElementById('monthlyChart').getContext('2d');
  const gradient = ctx.createLinearGradient(0, 0, 0, 300);
  gradient.addColorStop(0, 'rgba(34,193,195,0.3)');
  gradient.addColorStop(1, 'rgba(34,193,195,0)');
  if (monthlyChart) monthlyChart.destroy();
  monthlyChart = new Chart(ctx, {
    type: 'line',
    data: {
      labels,
      datasets: [{
        label: 'Revenue (₱)',
        data: values,
        borderColor: '#22C1C3',
        backgroundColor: gradient,
        tension: 0.4,
        fill: true,
        pointRadius: 5,
        pointHoverRadius: 8,
        pointBackgroundColor: '#fff',
        pointBorderColor: '#22C1C3',
        pointBorderWidth: 2,
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: {
        x: { grid: { display: false }, border: { dash: [4,4] } },
        y: {
          grid: { color: '#f0f4f8', borderDash: [4,4] },
          border: { display: false },
          ticks: { callback: v => '₱' + v.toLocaleString() }
        }
      }
    }
  });
};

const loadDaily = async () => {
  const ym = document.getElementById('ym').value;
  const data = await fetchJson('/yummy-soda/api/analytics_data.php?op=drilldown_day&ym=' + encodeURIComponent(ym));
  const labels = data.map(x => x.full_date);
  const values = data.map(x => Number(x.revenue));
  const ctx = document.getElementById('dailyChart').getContext('2d');
  if (dailyChart) dailyChart.destroy();
  dailyChart = new Chart(ctx, {
    type: 'bar',
    data: {
      labels,
      datasets: [{
        label: 'Daily Revenue (₱)',
        data: values,
        backgroundColor: 'rgba(253,187,45,0.8)',
        borderRadius: 6,
        borderSkipped: false,
        hoverBackgroundColor: '#fdbb2d',
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: {
        x: { grid: { display: false } },
        y: {
          grid: { color: '#f0f4f8', borderDash: [4,4] },
          border: { display: false },
          ticks: { callback: v => '₱' + v.toLocaleString() }
        }
      }
    }
  });
};

const loadSlice = async () => {
  const method = document.getElementById('method').value;
  const data = await fetchJson('/yummy-soda/api/analytics_data.php?op=slice_method&method=' + encodeURIComponent(method));
  const labels = data.map(x => x.product);
  const values = data.map(x => Number(x.revenue));
  const ctx = document.getElementById('sliceChart').getContext('2d');
  if (sliceChart) sliceChart.destroy();
  sliceChart = new Chart(ctx, {
    type: 'doughnut',
    data: {
      labels,
      datasets: [{
        data: values,
        backgroundColor: COLORS,
        borderWidth: 3,
        borderColor: '#fff',
        hoverOffset: 8,
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      cutout: '62%',
      plugins: {
        legend: { display: false },
        title: {
          display: true,
          text: method + ' — Revenue by Product',
          font: { size: 13, weight: '700' },
          color: '#374151',
        }
      }
    }
  });

  // Custom legend
  const legend = document.getElementById('sliceLegend');
  const total = values.reduce((a,b) => a+b, 0);
  legend.innerHTML = labels.map((l,i) => `
    <div style="display:flex;align-items:center;gap:10px;">
      <span style="width:12px;height:12px;border-radius:3px;background:${COLORS[i]};flex-shrink:0;"></span>
      <span style="font-size:13px;font-weight:600;flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${l}</span>
      <span style="font-size:12px;font-family:var(--font-mono);color:var(--text-muted);">${total ? Math.round(values[i]/total*100) : 0}%</span>
    </div>
  `).join('');
};

document.getElementById('drillBtn').addEventListener('click', loadDaily);
document.getElementById('sliceBtn').addEventListener('click', loadSlice);

function diceExport(format) {
  const from     = document.getElementById('dice_from').value;
  const to       = document.getElementById('dice_to').value;
  const category = document.getElementById('dice_category').value.trim();
  const method   = document.getElementById('dice_method').value;
  const endpoint = format === 'excel'
    ? '/yummy-soda/api/export_excel.php'
    : format === 'pdf'
    ? '/yummy-soda/api/export_pdf.php'
    : '/yummy-soda/api/export_csv.php';
  const params = new URLSearchParams({ from, to });
  if (category) params.set('category', category);
  if (method)   params.set('method', method);
  window.location.href = endpoint + '?' + params.toString();
}

loadMonthly();
loadDaily();
loadSlice();
</script>
</body>
</html>