<?php
declare(strict_types=1);

require_once __DIR__ . '/../cgi-bin/app/bootstrap.php';
require_once __DIR__ . '/../cgi-bin/app/auth.php';
include __DIR__ . '/../assets/includes/header.php';


requireRole(['ADMIN','BEHEER','FINANCIEEL','GEBRUIKER']);

$user = currentUser();
$role = $user['role'] ?? 'GEBRUIKER';

function h(?string $v): string {
  return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

auditLog($pdo, 'PAGE_VIEW', 'admin/help.php');
?>
<!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Porbeheer - Help</title>

  <style>
    :root{
      --text: #fff;
      --muted: rgba(255,255,255,.78);
      --border: rgba(255,255,255,.22);
      --glass: rgba(255,255,255,.12);
      --glass2: rgba(255,255,255,.06);
      --shadow: 0 14px 40px rgba(0,0,0,.45);
    }

    body{
      margin:0;
      font-family: Arial, sans-serif;
      color: var(--text);
      background: url('/assets/images/help-a.png') no-repeat center center fixed;
      background-size: cover;
    }

    .backdrop{
      min-height: 100vh;
      background:
        radial-gradient(circle at 25% 15%, rgba(0,0,0,.35), rgba(0,0,0,.75) 55%, rgba(0,0,0,.88)),
        linear-gradient(0deg, rgba(0,0,0,.35), rgba(0,0,0,.35));
      padding: 26px;
      box-sizing: border-box;
      display:flex;
      justify-content:center;
    }

    .wrap{ width: min(1200px, 96vw); }

    .topbar{
      display:flex;
      align-items:flex-end;
      justify-content:space-between;
      gap:16px;
      flex-wrap:wrap;
      margin-bottom: 14px;
    }

    .brand h1{ margin:0; font-size: 28px; letter-spacing: .5px; }
    .brand .sub{ margin-top:6px; color: var(--muted); font-size: 14px; }

    .userbox{
      background: var(--glass);
      border: 1px solid var(--border);
      border-radius: 14px;
      padding: 12px 14px;
      box-shadow: var(--shadow);
      backdrop-filter: blur(10px);
      -webkit-backdrop-filter: blur(10px);
      min-width: 260px;
    }
    .userbox .line1{font-weight:bold}
    .userbox .line2{color:var(--muted);margin-top:4px;font-size:13px}

    .panel{
      margin-top: 10px;
      border-radius: 20px;
      border: 1px solid rgba(255,255,255,.18);
      background: linear-gradient(180deg, rgba(255,255,255,.14), rgba(255,255,255,.06));
      box-shadow: var(--shadow);
      backdrop-filter: blur(12px);
      -webkit-backdrop-filter: blur(12px);
      padding: 18px;
    }

    a{color:#fff;text-decoration:none;transition:color .15s ease}
    a:hover{color:#ffd9b3}
    a:visited{color:#ffe0c2}

    /* Help content */
    .helphead{
      display:flex;
      justify-content:space-between;
      align-items:flex-end;
      gap:12px;
      flex-wrap:wrap;
      margin-bottom: 10px;
    }
    .helphead h2{margin:0;font-size:20px;letter-spacing:.2px}
    .helphead .hint{color:var(--muted);font-size:13px;margin-top:6px}

    .cards{
      display:grid;
      grid-template-columns: repeat(3, minmax(200px, 1fr));
      gap: 14px;
      margin-top: 12px;
    }
    @media (max-width: 960px){
      .cards{grid-template-columns: repeat(2, minmax(200px, 1fr));}
      .userbox{min-width: unset; width: 100%;}
    }
    @media (max-width: 520px){
      .cards{grid-template-columns: 1fr;}
    }

    .card{
      position: relative;
      border-radius: 16px;
      border: 1px solid rgba(255,255,255,.22);
      background: linear-gradient(180deg, var(--glass), var(--glass2));
      box-shadow: 0 10px 22px rgba(0,0,0,.30);
      backdrop-filter: blur(10px);
      -webkit-backdrop-filter: blur(10px);
      overflow:hidden;
      padding: 14px 14px 12px 14px;
      min-height: 110px;
    }
    .card::before{
      content:"";
      position:absolute;
      inset:-40%;
      background: radial-gradient(circle at 20% 30%, rgba(255,255,255,.22), transparent 45%);
      transform: rotate(12deg);
      pointer-events:none;
    }
    .card h3{
      position:relative;
      margin:0 0 8px 0;
      font-size: 16px;
      letter-spacing:.2px;
    }
    .card p{
      position:relative;
      margin:0;
      color: var(--muted);
      font-size: 13px;
      line-height: 1.45;
    }

    .kbar{
      display:flex;
      gap:10px;
      flex-wrap:wrap;
      margin-top: 12px;
    }
    .kbtn{
      display:inline-block;
      padding: 10px 12px;
      border-radius: 14px;
      border: 1px solid rgba(255,255,255,.22);
      background: linear-gradient(180deg, var(--glass), var(--glass2));
      box-shadow: 0 10px 22px rgba(0,0,0,.28);
      backdrop-filter: blur(10px);
      -webkit-backdrop-filter: blur(10px);
      font-weight: 800;
      letter-spacing: .2px;
      transition: transform .12s ease, border-color .12s ease, background .12s ease;
    }
    .kbtn:hover{
      transform: translateY(-2px);
      border-color: rgba(255,255,255,.38);
      background: linear-gradient(180deg, rgba(255,255,255,.20), rgba(255,255,255,.08));
    }

    .notice{
      margin-top: 14px;
      padding: 12px 14px;
      border-radius: 16px;
      border: 1px solid rgba(255,255,255,.20);
      background: rgba(255,255,255,.06);
      color: var(--muted);
      font-size: 13px;
      line-height: 1.45;
    }
    .notice code{
      color:#fff;
      background: rgba(0,0,0,.25);
      padding: 2px 6px;
      border-radius: 10px;
      border: 1px solid rgba(255,255,255,.12);
    }
  </style>
</head>

<body>
  <div class="backdrop">
    <div class="wrap">
      <div class="topbar">
        <div class="brand">
          <h1>Porbeheer</h1>
          <div class="sub">POP Oefenruimte Zevenaar • beheer & planning</div>
        </div>

        <div class="userbox">
          <div class="line1">Hallo <?= h($user['username'] ?? '') ?> · Jouw rol is <?= h($role) ?></div>
          <div class="line2">
            <a href="/admin/dashboard.php">Dashboard</a>
            &nbsp;•&nbsp;
            <a href="/logout.php">Uitloggen</a>
          </div>
        </div>
      </div>

      <div class="panel">
        <div class="helphead">
          <div>
            <h2>Help & uitleg</h2>
            <div class="hint">Korte handleiding voor de belangrijkste onderdelen van Porbeheer.</div>
          </div>
          <div class="hint">Pagina: <code>admin/help.php</code></div>
        </div>

        <div class="kbar">
          <a class="kbtn" href="/admin/bands.php">Bands</a>
          <a class="kbtn" href="/admin/planning.php">Planning</a>
          <a class="kbtn" href="/admin/keys.php">Sleutels</a>
          <a class="kbtn" href="/admin/finance.php">Financiën</a>
          <a class="kbtn" href="/admin/contacts.php">Contacten</a>
          <?php if ($role === 'ADMIN'): ?>
            <a class="kbtn" href="/admin/beheer.php">Beheer</a>
          <?php endif; ?>
        </div>

        <div class="cards">
          <div class="card">
            <h3>Bands</h3>
            <p>
              Beheer bandgegevens, contactpersonen en (indien aanwezig) lockers/porkasten.
              Gebruik “Wijzigen” om gegevens aan te passen.
            </p>
          </div>

          <div class="card">
            <h3>Planning</h3>
            <p>
              Maak/werk repetities en optredens bij. Let op: alleen ADMIN/BEHEER kunnen wijzigingen doen.
              Controleer altijd datum, tijd en band.
            </p>
          </div>

          <div class="card">
            <h3>Sleutels</h3>
            <p>
              Registreer uitgifte en retour van sleutels. Dit helpt bij verantwoordelijkheid en traceerbaarheid.
              Wijzigingen worden gelogd.
            </p>
          </div>

          <div class="card">
            <h3>Financiën</h3>
            <p>
              Overzicht van betalingen en abonnementen. FINANCIEEL en ADMIN hebben toegang.
              Gebruik filters en export (indien aanwezig).
            </p>
          </div>

          <div class="card">
            <h3>Contacten</h3>
            <p>
              Beheer contactgegevens en koppelingen met bands. Soft-delete wordt gebruikt; verwijderde items
              blijven traceerbaar.
            </p>
          </div>

          <div class="card">
            <h3>Beveiliging</h3>
            <p>
              Toegang wordt afgedwongen via <code>requireRole()</code>. Acties worden vastgelegd met
              <code>auditLog()</code>. Formulieren gebruiken CSRF.
            </p>
          </div>
        </div>

        <div class="notice">
          Tip: Zie je iets dat “leeg” lijkt? Controleer dan of je rol voldoende rechten heeft en of de betreffende items
          niet soft-deleted zijn (<code>deleted_at IS NULL</code>).
        </div>

      </div>
    </div>
  </div>
</body>
</html>