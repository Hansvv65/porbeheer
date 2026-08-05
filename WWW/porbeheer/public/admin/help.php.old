<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../libs/porbeheer/app/bootstrap.php';
require_once __DIR__ . '/../../../libs/porbeheer/app/auth.php';
include __DIR__ . '/../assets/includes/header.php';


requireRole(['ADMIN','BEHEER','FINANCIEEL','GEBRUIKER']);

$user = currentUser();
$role = $user['role'] ?? 'GEBRUIKER';
$bg = themeImage('help', $pdo);

function h(?string $v): string
{
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
    --text:#fff;
    --muted:rgba(255,255,255,.78);
    --border:rgba(255,255,255,.22);
    --glass:rgba(255,255,255,.12);
    --glass2:rgba(255,255,255,.06);
    --shadow:0 14px 40px rgba(0,0,0,.45);
    --ok:#7CFFB2;
    --err:#FF8DA1;
    --accent:#ffd86b;
  }

  html{
    scroll-behavior:smooth;
  }

  body{
    margin:0;
    font-family:Arial,sans-serif;
    color:var(--text);
    background:url('<?= h($bg) ?>') no-repeat center center fixed;
    background-size:cover;
  }

  .backdrop{
    min-height:100vh;
    background:
      radial-gradient(circle at 25% 15%, rgba(0,0,0,.35), rgba(0,0,0,.75) 55%, rgba(0,0,0,.88)),
      linear-gradient(0deg, rgba(0,0,0,.35), rgba(0,0,0,.35));
    padding:26px;
    box-sizing:border-box;
    display:flex;
    justify-content:center;
  }

  .wrap{
    width:min(1180px, 96vw);
  }

  .topbar{
    display:flex;
    align-items:flex-end;
    justify-content:space-between;
    gap:16px;
    flex-wrap:wrap;
    margin-bottom:14px;
  }

  .brand h1{
    margin:0;
    font-size:28px;
    letter-spacing:.5px;
  }

  .brand .sub{
    margin-top:6px;
    color:var(--muted);
    font-size:14px;
  }

  .userbox{
    background:var(--glass);
    border:1px solid var(--border);
    border-radius:14px;
    padding:12px 14px;
    box-shadow:var(--shadow);
    backdrop-filter:blur(10px);
    -webkit-backdrop-filter:blur(10px);
    min-width:260px;
  }

  .userbox .line1{
    font-weight:bold;
  }

  .userbox .line2{
    color:var(--muted);
    margin-top:4px;
    font-size:13px;
  }

  a{
    color:#fff;
    text-decoration:none;
  }

  a:visited{
    color:var(--accent);
  }

  a:hover{
    opacity:.95;
    text-decoration:underline;
  }

  .panel{
    margin-top:10px;
    border-radius:20px;
    border:1px solid rgba(255,255,255,.18);
    background:linear-gradient(180deg, rgba(255,255,255,.14), rgba(255,255,255,.06));
    box-shadow:var(--shadow);
    backdrop-filter:blur(12px);
    -webkit-backdrop-filter:blur(12px);
    padding:18px;
  }

  .hero{
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:16px;
    flex-wrap:wrap;
  }

  .hero h2{
    margin:0 0 8px 0;
  }

  .muted{
    color:var(--muted);
    font-size:13px;
    margin-top:6px;
  }

  .search{
    width:min(360px, 100%);
  }

  .search input[type="text"]{
    width:100%;
    padding:12px 14px;
    border-radius:12px;
    border:1px solid rgba(255,255,255,.22);
    background:rgba(0,0,0,.25);
    color:#fff;
    outline:none;
    box-sizing:border-box;
    font-size:15px;
  }

  .search input[type="text"]:focus{
    border-color:rgba(255,255,255,.38);
    box-shadow:0 0 0 3px rgba(255,255,255,.10);
  }

  .quicknav{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    margin-top:16px;
  }

  .chip{
    display:inline-block;
    padding:10px 14px;
    border-radius:12px;
    border:1px solid rgba(255,255,255,.22);
    background:rgba(255,255,255,.10);
    color:#fff;
    font-weight:800;
    box-shadow:0 10px 22px rgba(0,0,0,.20);
    transition:transform .12s ease, background .12s ease, border-color .12s ease;
    text-decoration:none;
  }

  .chip:hover{
    transform:translateY(-1px);
    background:rgba(255,255,255,.18);
    border-color:rgba(255,255,255,.35);
    text-decoration:none;
  }

  .layout{
    display:grid;
    grid-template-columns:280px 1fr;
    gap:16px;
    margin-top:16px;
  }

  @media (max-width: 920px){
    .layout{
      grid-template-columns:1fr;
    }
  }

  .sidepanel{
    border-radius:18px;
    border:1px solid rgba(255,255,255,.14);
    background:rgba(0,0,0,.14);
    padding:14px;
    height:max-content;
    position:sticky;
    top:16px;
  }

  .sidepanel h3{
    margin:0 0 10px 0;
    font-size:18px;
  }

  .menu{
    display:flex;
    flex-direction:column;
    gap:8px;
  }

  .menu a{
    display:block;
    padding:10px 12px;
    border-radius:12px;
    border:1px solid rgba(255,255,255,.10);
    background:rgba(255,255,255,.05);
    font-weight:700;
    text-decoration:none;
  }

  .menu a:hover{
    background:rgba(255,255,255,.14);
    border-color:rgba(255,255,255,.22);
    text-decoration:none;
  }

  .content{
    display:flex;
    flex-direction:column;
    gap:16px;
  }

  .section{
    border-radius:18px;
    border:1px solid rgba(255,255,255,.14);
    background:rgba(0,0,0,.14);
    padding:18px;
  }

  .section h3{
    margin:0 0 8px 0;
    font-size:22px;
  }

  .section p{
    margin:10px 0;
    line-height:1.55;
  }

  .section ul,
  .section ol{
    margin:10px 0 0 20px;
    line-height:1.6;
  }

  .section li{
    margin:6px 0;
  }

  .icon{
    margin-right:8px;
  }

  .mini-links{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    margin-top:14px;
  }

  .btn{
    display:inline-block;
    padding:10px 14px;
    border-radius:12px;
    border:1px solid rgba(255,255,255,.22);
    background:rgba(255,255,255,.14);
    color:#fff;
    font-weight:800;
    cursor:pointer;
    box-shadow:0 10px 22px rgba(0,0,0,.20);
    transition:transform .12s ease, background .12s ease, border-color .12s ease;
    text-decoration:none;
  }

  .btn:hover{
    transform:translateY(-1px);
    background:rgba(255,255,255,.18);
    border-color:rgba(255,255,255,.35);
    text-decoration:none;
  }

  .btn.ok{
    border-color:rgba(124,255,178,.35);
    background:rgba(124,255,178,.10);
  }

  .help-note{
    margin-top:12px;
    padding:12px 14px;
    border-radius:12px;
    border:1px solid rgba(255,255,255,.14);
    background:rgba(255,255,255,.06);
    color:var(--muted);
    font-size:14px;
  }

  .hide{
    display:none !important;
  }
</style>
</head>
<body>
<div class="backdrop">
  <div class="wrap">

    <div class="topbar">
      <div class="brand">
        <h1>Porbeheer</h1>
        <div class="sub">POP Oefenruimte Zevenaar • help</div>
      </div>

      <div class="userbox">
        <div class="line1">Ingelogd: <?= h($user['username'] ?? '') ?> • Rol: <?= h($role) ?></div>
        <div class="line2">
          <a href="/admin/dashboard.php">Dashboard</a> •
          <a href="/admin/beheer.php">Beheer</a>
        </div>
      </div>
    </div>

    <div class="panel">
      <div class="hero">
        <div>
          <h2 style="margin:0 0 8px 0;">Help en uitleg</h2>
          <div class="muted">
            Korte en duidelijke uitleg voor het gebruik van Porbeheer.
            Gebruik de knoppen of het menu om snel naar een onderdeel te springen.
          </div>
        </div>

        <div class="search">
          <input type="text" id="helpSearch" placeholder="Zoek in de help, bijvoorbeeld: band, sleutel, planning">
        </div>
      </div>

      <div class="quicknav">
        <a class="chip" href="#algemeen">Algemeen</a>
        <a class="chip" href="#bands">Bands</a>
        <a class="chip" href="#sleutels">Sleutels & kasten</a>
        <a class="chip" href="#planning">Planning</a>
        <a class="chip" href="#contacten">Contacten</a>
        <a class="chip" href="#financien">Financiën</a>
        <a class="chip" href="#beheer">Beheer</a>
      </div>

      <div class="layout">

        <aside class="sidepanel">
          <h3>Snelmenu</h3>
          <div class="menu">
            <a href="#algemeen">🏠 Algemeen</a>
            <a href="#bands">🎸 Bands</a>
            <a href="#sleutels">🔑 Sleutels en kasten</a>
            <a href="#planning">📅 Planning</a>
            <a href="#contacten">📇 Contacten</a>
            <a href="#financien">💶 Financiën</a>
            <a href="#beheer">⚙️ Beheer</a>
          </div>

          <div class="help-note">
            Tip: begin meestal op het <a href="/admin/dashboard.php">dashboard</a>.
            Van daaruit ga je snel naar de juiste pagina.
          </div>
        </aside>

        <main class="content" id="helpContent">

          <section class="section help-section" id="algemeen" data-search="algemeen dashboard overzicht navigatie uitleg hulp start">
            <h3><span class="icon">🏠</span>Algemeen</h3>

            <p>
              Welkom in Porbeheer. Dit systeem helpt bij het beheren van
              <a href="/admin/bands.php">bands</a>,
              <a href="/admin/contacts.php">contacten</a>,
              <a href="/admin/keys.php">sleutels en kasten</a>,
              <a href="/admin/planning.php">planning</a>
              en waar beschikbaar ook
              <a href="/admin/finance.php">financiën</a>.
            </p>

            <p>
              De meeste onderdelen werken op dezelfde manier:
            </p>

            <ol>
              <li>Open eerst het overzicht van een onderdeel, bijvoorbeeld <a href="/admin/bands.php">Bands</a> of <a href="/admin/planning.php">Planning</a>.</li>
              <li>Klik op een naam of regel om details te bekijken.</li>
              <li>Gebruik knoppen als <strong>Nieuw</strong>, <strong>Toevoegen</strong>, <strong>Wijzigen</strong> of <strong>Opslaan</strong>.</li>
              <li>Ga terug via de knop op de pagina of via het <a href="/admin/dashboard.php">dashboard</a>.</li>
            </ol>

            <p>
              Weet je niet waar je moet beginnen, ga dan naar het
              <a href="/admin/dashboard.php">dashboard</a>. Daar staat de snelste ingang naar alle onderdelen.
            </p>
          </section>

          <section class="section help-section" id="bands" data-search="bands band toevoegen wijzigen beheren contactpersonen overzicht">
            <h3><span class="icon">🎸</span>Bands</h3>

            <p>
              Op de pagina <a href="/admin/bands.php">Bands</a> beheer je alle bands die gebruik maken van de oefenruimte.
            </p>

            <p><strong>Een band toevoegen</strong></p>
            <ol>
              <li>Ga naar <a href="/admin/bands.php">Bands</a>.</li>
              <li>Klik op <strong>Nieuwe band</strong>.</li>
              <li>Vul de gegevens in.</li>
              <li>Sla de band op.</li>
            </ol>

            <p><strong>Een band wijzigen</strong></p>
            <ol>
              <li>Open <a href="/admin/bands.php">het bandoverzicht</a>.</li>
              <li>Klik op de naam van de band.</li>
              <li>Pas de gegevens aan.</li>
              <li>Klik op opslaan.</li>
            </ol>

            <p>
              Bandcontacten beheer je via <a href="/admin/contacts.php">Contacten</a>.
              Daar kun je personen opzoeken en koppelen aan een band, als jouw pagina dat ondersteunt.
            </p>

            <div class="mini-links">
              <a class="btn" href="/admin/bands.php">Naar Bands</a>
              <a class="btn" href="/admin/contacts.php">Naar Contacten</a>
            </div>
          </section>

          <section class="section help-section" id="sleutels" data-search="sleutels sleutel kasten kast porkast opslag uitgifte terugnemen">
            <h3><span class="icon">🔑</span>Sleutels en kasten</h3>

            <p>
              Op <a href="/admin/keys.php">Sleutels</a> beheer je wie welke sleutel of kast gebruikt.
            </p>

            <p><strong>Een sleutel uitgeven</strong></p>
            <ol>
              <li>Ga naar <a href="/admin/keys.php">Sleutels</a>.</li>
              <li>Zoek de juiste sleutel op.</li>
              <li>Koppel de sleutel aan de juiste band of persoon.</li>
              <li>Sla de wijziging op.</li>
            </ol>

            <p><strong>Een sleutel terugnemen</strong></p>
            <ol>
              <li>Open de sleutel in <a href="/admin/keys.php">het sleuteloverzicht</a>.</li>
              <li>Verwijder of wijzig de koppeling.</li>
              <li>Sla de wijziging op.</li>
            </ol>

            <p><strong>Kasten</strong></p>
            <ul>
              <li>Een kast kan aan een band gekoppeld worden.</li>
              <li>Zo zie je snel welke band welke opslag gebruikt.</li>
              <li>Controleer af en toe of de koppeling nog klopt.</li>
            </ul>

            <div class="mini-links">
              <a class="btn" href="/admin/keys.php">Naar Sleutels</a>
              <a class="btn" href="/admin/bands.php">Naar Bands</a>
            </div>
          </section>

          <section class="section help-section" id="planning" data-search="planning agenda afspraak afspraken rooster datum tijd band oefenruimte">
            <h3><span class="icon">📅</span>Planning</h3>

            <p>
              Via <a href="/admin/planning.php">Planning</a> plan je oefenmomenten en zie je wanneer de ruimte in gebruik is.
            </p>

            <p><strong>Een nieuwe afspraak maken</strong></p>
            <ol>
              <li>Ga naar <a href="/admin/planning.php">Planning</a>.</li>
              <li>Klik op <strong>Nieuwe afspraak</strong>.</li>
              <li>Kies de band, datum en tijd.</li>
              <li>Sla de afspraak op.</li>
            </ol>

            <p><strong>Een afspraak wijzigen</strong></p>
            <ol>
              <li>Open de afspraak in <a href="/admin/planning.php">de planning</a>.</li>
              <li>Pas datum, tijd of band aan.</li>
              <li>Sla opnieuw op.</li>
            </ol>

            <p>
              Controleer altijd eerst in <a href="/admin/planning.php">de planning</a> of het tijdslot nog vrij is.
            </p>

            <div class="mini-links">
              <a class="btn" href="/admin/planning.php">Naar Planning</a>
              <a class="btn" href="/admin/bands.php">Naar Bands</a>
            </div>
          </section>

          <section class="section help-section" id="contacten" data-search="contacten contact telefoon email mail koppelen personen">
            <h3><span class="icon">📇</span>Contacten</h3>

            <p>
              In <a href="/admin/contacts.php">Contacten</a> beheer je namen, telefoonnummers en e-mailadressen van contactpersonen.
            </p>

            <ul>
              <li>Gebruik duidelijke namen zodat een contact makkelijk terug te vinden is.</li>
              <li>Vul telefoon en e-mail zo volledig mogelijk in.</li>
              <li>Koppel contactpersonen waar nodig aan een band.</li>
            </ul>

            <p>
              Zoek je een contact van een band, kijk dan eerst bij <a href="/admin/bands.php">Bands</a> en daarna in <a href="/admin/contacts.php">Contacten</a>.
            </p>

            <div class="mini-links">
              <a class="btn" href="/admin/contacts.php">Naar Contacten</a>
              <a class="btn" href="/admin/bands.php">Naar Bands</a>
            </div>
          </section>

          <section class="section help-section" id="financien" data-search="financien financiën geld betalingen abonnement prijs prijzen dagdeel maand instellingen">
            <h3><span class="icon">💶</span>Financiën</h3>

            <p>
              Op <a href="/admin/finance.php">Financiën</a> beheer je betalingen en financiële overzichten, voor zover jouw rol daar toegang toe heeft.
            </p>

            <ul>
              <li>Controleer openstaande bedragen.</li>
              <li>Bekijk betalingen of bijdragen.</li>
              <li>Gebruik <a href="/admin/settings.php">Instellingen</a> voor vaste bedragen als jouw beheerpagina dat ondersteunt.</li>
            </ul>

            <p>
              Prijzen zoals abonnement per maand of prijs per dagdeel stel je in via
              <a href="/admin/settings.php">Configuratie</a>.
            </p>

            <div class="mini-links">
              <a class="btn" href="/admin/finance.php">Naar Financiën</a>
              <a class="btn" href="/admin/settings.php">Naar Configuratie</a>
            </div>
          </section>

          <section class="section help-section" id="beheer" data-search="beheer admin gebruikers audit instellingen configuratie rechten rollen">
            <h3><span class="icon">⚙️</span>Beheer</h3>

            <p>
              Het beheergedeelte is bedoeld voor gebruikers met extra rechten, zoals ADMIN of BEHEER.
            </p>

            <ul>
              <li>Ga naar <a href="/admin/beheer.php">Beheer</a> voor beheerfuncties.</li>
              <li>Gebruikers beheer je via <a href="/admin/users.php">Gebruikers</a>.</li>
              <li>Instellingen beheer je via <a href="/admin/settings.php">Configuratie</a>.</li>
              <li>Controle en logging bekijk je via <a href="/admin/audit.php">Audit log</a>, als die pagina aanwezig is.</li>
            </ul>

            <p>
              Pas beheerinstellingen alleen aan als je zeker weet wat het effect is.
            </p>

            <div class="mini-links">
              <a class="btn" href="/admin/beheer.php">Naar Beheer</a>
              <a class="btn" href="/admin/users.php">Naar Gebruikers</a>
              <a class="btn" href="/admin/settings.php">Naar Configuratie</a>
            </div>
          </section>

        </main>
      </div>
    </div>

  </div>
</div>

<script>
(function(){
  const input = document.getElementById('helpSearch');
  const sections = Array.from(document.querySelectorAll('.help-section'));

  function normalize(s){
    return (s || '').toLowerCase().trim();
  }

  function runSearch(){
    const q = normalize(input.value);

    sections.forEach(section => {
      const hay =
        normalize(section.innerText) + ' ' +
        normalize(section.getAttribute('data-search'));

      if (q === '' || hay.indexOf(q) !== -1) {
        section.classList.remove('hide');
      } else {
        section.classList.add('hide');
      }
    });
  }

  input.addEventListener('input', runSearch);
})();
</script>
</body>
</html>