<?php

declare(strict_types=1);

// Terms of use, privacy policy and the members' consent to them.
// When either document changes in substance, set TERMS_VERSION to the new date:
// every member is then asked once to accept the new version (consent_page), and the
// acceptance is stored with the version, time and IP address (table consents).
const TERMS_VERSION = '2026-11-07';

// The legal entity that runs GANG.lt. Shown in both documents.
const OPERATOR_NAME = 'MB „Rundfunkbeitrag support“';
const OPERATOR_DETAILS = 'juridinio asmens kodas 307030376, buveinės adresas Beržų g. 38, LT-36202 Panevėžys';
const OPERATOR_EMAIL = 'hello@gang.lt';

function terms_date(): string
{
    return lt_date(TERMS_VERSION);
}

function lt_date(string $ymd): string
{
    $months = ['sausio', 'vasario', 'kovo', 'balandžio', 'gegužės', 'birželio', 'liepos', 'rugpjūčio', 'rugsėjo', 'spalio', 'lapkričio', 'gruodžio'];
    [$y, $m, $d] = array_map('intval', explode('-', $ymd));
    return $y.' m. '.$months[$m - 1].' '.$d.' d.';
}

// The consent checkbox shown wherever an account can be created.
function consent_field(string $error = ''): string
{
    return '<label class="check consent"><input type="checkbox" name="terms" value="1" required>'
        .'<span>Sutinku su GANG <a href="/taisykles" target="_blank">Naudojimosi taisyklėmis</a> ir susipažinau su <a href="/privatumas" target="_blank">Privatumo politika</a>.</span></label>'
        .($error !== '' ? '<span class="err">'.e($error).'</span>' : '');
}

function consent_given(): bool
{
    return input('terms') === '1';
}

const CONSENT_ERROR = 'Norėdami tęsti, pažymėkite, kad sutinkate su Naudojimosi taisyklėmis.';

// Proof of consent: one row per acceptance, plus the current version on the user row.
function record_consent(int $userId): void
{
    $now = now();
    insert('consents', [
        'user_id' => $userId,
        'version' => TERMS_VERSION,
        'accepted_at' => $now,
        'ip' => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
        'user_agent' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
    ]);
    sql('UPDATE users SET terms_version = ?, terms_accepted_at = ? WHERE id = ?', [TERMS_VERSION, $now, $userId]);
}

function needs_consent(array $user): bool
{
    return ($user['terms_version'] ?? '') !== TERMS_VERSION;
}

// Members who have not accepted the current version (older accounts, or after a change) accept it once here.
function consent_page(): never
{
    start_session();
    $user = current_user();
    if (!$user) {
        redirect('/prisijungti');
    }
    if (!needs_consent($user)) {
        redirect('/mano');
    }
    $error = '';
    if (is_post()) {
        check_csrf();
        if (consent_given()) {
            record_consent((int) $user['id']);
            if (!profile_complete($user)) {
                redirect('/profilis');
            }
            $next = $_SESSION['after_login'] ?? '/mano';
            unset($_SESSION['after_login']);
            redirect(str_starts_with($next, '/') && !str_starts_with($next, '//') ? $next : '/mano');
        }
        $error = CONSENT_ERROR;
    }
    $updated = ($user['terms_version'] ?? '') !== '';
    $title = $updated ? 'Atnaujinome taisykles' : 'Taisyklės ir privatumas';
    $lead = $updated
        ? 'Atnaujinome GANG Naudojimosi taisykles ir Privatumo politiką (redakcija nuo '.terms_date().'). Prašome su jomis susipažinti ir patvirtinti, kad sutinkate.'
        : 'Prieš tęsdami, susipažinkite su GANG Naudojimosi taisyklėmis ir Privatumo politika ir patvirtinkite, kad sutinkate.';
    page($title, '<section class="wrap narrow"><h1>'.$title.'</h1><p class="lead">'.$lead.'</p>'
        .'<form method="post" class="form" novalidate>'.csrf_field().consent_field($error)
        .'<button class="btn">Tęsti</button></form>'
        .'<form method="post" action="/atsijungti" class="inline">'.csrf_field().'<button class="linklike">Atsijungti</button></form></section>', ['noindex' => true]);
}

function legal_page(string $title, string $canonical, string $intro, array $sections): never
{
    $index = '';
    $body = '';
    foreach ($sections as $i => [$id, $h, $html]) {
        $n = $i + 1;
        $index .= '<li><a href="#'.$id.'">'.e($h).'</a></li>';
        $body .= '<h2 id="'.$id.'">'.$n.'. '.e($h).'</h2>'.$html;
    }
    page($title, '<section class="wrap narrow prose legal"><h1>'.e($title).'</h1>'
        .'<p class="meta">Redakcija galioja nuo '.terms_date().'</p>'.$intro
        .'<nav class="legal-index" aria-label="Turinys"><ol>'.$index.'</ol></nav>'.$body
        .'</section>', ['canonical' => $canonical]);
}

function operator_line(): string
{
    return '<strong>'.e(OPERATOR_NAME).'</strong>, '.e(OPERATOR_DETAILS).', el. paštas <a href="mailto:'.OPERATOR_EMAIL.'">'.OPERATOR_EMAIL.'</a>';
}

function terms_page(): never
{
    $mail = '<a href="mailto:'.OPERATOR_EMAIL.'">'.OPERATOR_EMAIL.'</a>';
    legal_page('Naudojimosi taisyklės', '/taisykles',
        '<p>Šios taisyklės nustato, kaip naudotis GANG.lt platforma. Registruodamiesi ir naudodamiesi GANG, patvirtinate, kad su taisyklėmis susipažinote ir jų laikysitės. Kaip tvarkome asmens duomenis, aprašyta <a href="/privatumas">Privatumo politikoje</a>.</p>',
        [
            ['bendrosios', 'Bendrosios nuostatos', '<p>GANG.lt (toliau – GANG) yra internetinė platforma, padedanti barams, restoranams, kavinėms ir kitoms hospitality sektoriaus darbovietėms (toliau – darbdaviai) rasti darbuotojus, o darbuotojams – darbo pasiūlymus. Platformą valdo '.operator_line().' (toliau – mes).</p>'
                .'<p>Taisyklės taikomos visiems GANG nariams: darbuotojams ir darbdaviams.</p>'],
            ['vaidmuo', 'GANG vaidmuo', '<p>GANG tik padeda darbdaviams ir darbuotojams rasti vieni kitus ir susisiekti. GANG nėra darbdavys, įdarbinimo agentūra ar laikinojo įdarbinimo įmonė, nesudaro darbo sutarčių, nemoka atlygio ir nėra darbdavio ir darbuotojo susitarimo šalis.</p>'
                .'<p>Darbo sutartį, atlygį, atsiskaitymą, darbo laiką ir kitas sąlygas darbdavys ir darbuotojas suderina tiesiogiai. Darbdavys yra atsakingas už tai, kad įdarbinimas atitiktų darbo, mokesčių, socialinio draudimo, darbuotojų saugos ir kitus teisės aktus.</p>'],
            ['paskyra', 'Registracija ir paskyra', '<ul>'
                .'<li>Naudotis GANG gali asmenys, kuriems yra bent 18 metų. Darbdavio paskyrą kuriantis asmuo patvirtina, kad turi teisę atstovauti darbovietei.</li>'
                .'<li>Vienu el. pašto adresu galima sukurti vieną paskyrą. Registruodamiesi pasirenkate, ar ieškote darbo, ar darbuotojų; vėliau šio pasirinkimo pakeisti negalima.</li>'
                .'<li>Prisijungiama vienkartiniu kodu, kurį atsiunčiame el. paštu. Kodo niekam neperduokite. Už veiksmus, atliktus jūsų paskyroje, atsakote jūs.</li>'
                .'<li>Nurodykite tikrus ir aktualius duomenis: vardą, telefono numerį, patirtį, darbovietės informaciją. Pasikeitusius duomenis atnaujinkite.</li></ul>'],
            ['darbdaviai', 'Darbdavių įsipareigojimai', '<ul>'
                .'<li>Skelbti tik tikras darbuotojų paieškas, kurių tikrai reikia, ir nurodyti tikslų atlygį, darbo laiką bei kitas sąlygas.</li>'
                .'<li>Laikytis paskelbtų sąlygų arba iš anksto aiškiai apie jų pakeitimus informuoti darbuotoją.</li>'
                .'<li>Įdarbinti teisėtai ir sumokėti sutartą atlygį.</li>'
                .'<li>Nediskriminuoti kandidatų dėl lyties, amžiaus, tautybės, kilmės, religijos, negalios, seksualinės orientacijos ar kitų teisės aktų saugomų požymių.</li>'
                .'<li>Darbuotojų duomenis ir kontaktus naudoti tik su darbo pasiūlymu susijusiam bendravimui.</li></ul>'],
            ['darbuotojai', 'Darbuotojų įsipareigojimai', '<ul>'
                .'<li>Profilyje nurodyti tikrą patirtį ir pareigas, kurias iš tiesų galite atlikti.</li>'
                .'<li>Teikti kandidatūrą tik tiems pasiūlymams, kuriems tikrai galite dirbti nurodytu laiku.</li>'
                .'<li>Atvykti į patvirtintą darbą laiku arba, jei negalite, kuo anksčiau jį atšaukti per GANG.</li>'
                .'<li>Darbdavių kontaktus naudoti tik su darbo pasiūlymu susijusiam bendravimui.</li></ul>'],
            ['kontraktas', 'GANG kontraktas, atšaukimai ir patikimumas', '<p>GANG kontraktas sudaromas, kai darbdavys patvirtina darbuotojo kandidatūrą. Tai GANG įrašas apie šalių susitarimą dirbti kartu, o ne darbo sutartis. Po patvirtinimo šalys mato viena kitos vardą ir telefono numerį; el. pašto adresai kitai šaliai nerodomi.</p>'
                .'<p>Bet kuri šalis gali atšaukti kontraktą, nurodydama priežastį. Vėlyvi atšaukimai ir darbdavio pažymėti neatvykimai matomi kitiems nariams kaip patikimumo rodiklis. Jei manote, kad žyma neteisinga, parašykite mums '.$mail.'.</p>'],
            ['ivertinimai', 'Įvertinimai', '<ul>'
                .'<li>Įvertinti vienas kitą darbdavys ir darbuotojas gali tik po GANG kontrakto.</li>'
                .'<li>Įvertinimai turi būti sąžiningi ir pagrįsti tikra darbo patirtimi. Draudžiama įvertinimais grasinti, juos pirkti, keisti į naudą ar kitaip jais piktnaudžiauti.</li>'
                .'<li>Įvertinimo autorius kitiems nariams nerodomas. Įžeidžiančius, klaidinančius ar asmeninę informaciją atskleidžiančius komentarus galime pašalinti.</li></ul>'],
            ['draudziama', 'Draudžiama', '<ul>'
                .'<li>skelbti melagingą, klaidinančią, įžeidžiančią ar neteisėtą informaciją;</li>'
                .'<li>apsimesti kitu asmeniu ar darboviete arba naudotis kito asmens paskyra;</li>'
                .'<li>naudoti GANG reklamai, šlamštui, kitų paslaugų siūlymui ar narių duomenų rinkimui;</li>'
                .'<li>automatinėmis priemonėmis kopijuoti platformos turinį ar duomenis;</li>'
                .'<li>bandyti apeiti platformos apsaugos priemones ar trikdyti jos veikimą.</li></ul>'],
            ['kaina', 'Kaina', '<p>Šiuo metu naudotis GANG nemokama. Jei ateityje darbdaviams atsirastų mokamų paslaugų, apie jas ir jų kainas pranešime iš anksto, o mokėti reikės tik jums su tuo atskirai sutikus. Darbuotojams GANG yra nemokamas.</p>'],
            ['turinys', 'Jūsų turinys', '<p>Profilio, darbovietės ir paieškų informacija, nuotraukos ir logotipai lieka jūsų. Juos skelbdami suteikiate mums teisę neatlygintinai rodyti šį turinį GANG platformoje ir siunčiamuose pranešimuose tiek, kiek reikia platformai veikti. Skelbkite tik turinį, kurį turite teisę naudoti.</p>'],
            ['atsakomybe', 'Atsakomybė', '<p>Stengiamės, kad GANG veiktų patikimai, tačiau negalime užtikrinti, kad platforma veiks be trikdžių. Narių pateiktos informacijos (patirties, darbo sąlygų, įvertinimų) iš anksto netikriname ir už jos tikslumą atsako ją pateikęs narys.</p>'
                .'<p>Neatsakome už darbdavių ir darbuotojų tarpusavio susitarimus, jų vykdymą, atsiskaitymą ar žalą, kilusią dėl šių santykių. Ši nuostata neriboja atsakomybės tais atvejais, kai jos riboti neleidžia teisės aktai.</p>'],
            ['sustabdymas', 'Paskyros sustabdymas ir ištrynimas', '<p>Taisyklių nesilaikančių narių paskyras galime laikinai sustabdyti arba užblokuoti, o jų turinį pašalinti. Apie tai, išskyrus akivaizdaus piktnaudžiavimo atvejus, informuosime el. paštu.</p>'
                .'<p>Paskyrą bet kada galite ištrinti parašę mums '.$mail.' iš el. pašto adreso, kuriuo esate užsiregistravę.</p>'],
            ['pakeitimai', 'Taisyklių pakeitimai', '<p>Taisykles galime keisti, pavyzdžiui, atsiradus naujoms funkcijoms ar pasikeitus teisės aktams. Apie esminius pakeitimus informuosime iš anksto, o kitą kartą prisijungus paprašysime patvirtinti, kad sutinkate su nauja redakcija.</p>'],
            ['teise', 'Taikoma teisė ir ginčai', '<p>Taisyklėms taikoma Lietuvos Respublikos teisė. Ginčus pirmiausia stengiamės išspręsti derybomis, todėl su pretenzijomis kreipkitės '.$mail.'. Vartotojai taip pat gali kreiptis į Valstybinę vartotojų teisių apsaugos tarnybą (<a href="https://vvtat.lt" rel="noopener" target="_blank">vvtat.lt</a>). Neišspręsti ginčai sprendžiami Lietuvos Respublikos teismuose.</p>'],
            ['kontaktai', 'Kontaktai', '<p>'.operator_line().'.</p>'],
        ]);
}

function privacy_page(): never
{
    $mail = '<a href="mailto:'.OPERATOR_EMAIL.'">'.OPERATOR_EMAIL.'</a>';
    legal_page('Privatumo politika', '/privatumas',
        '<p>Šioje politikoje paaiškiname, kokius asmens duomenis tvarkome, kai naudojatės GANG.lt, kodėl tai darome, kas juos mato ir kokias teises turite. Duomenis tvarkome pagal Bendrąjį duomenų apsaugos reglamentą (BDAR) ir Lietuvos Respublikos teisės aktus.</p>',
        [
            ['valdytojas', 'Duomenų valdytojas', '<p>'.operator_line().'. Visais duomenų apsaugos klausimais rašykite šiuo adresu.</p>'],
            ['duomenys', 'Kokius duomenis tvarkome', '<ul>'
                .'<li><strong>Paskyros duomenys:</strong> el. pašto adresas, paskyros tipas (darbuotojas ar darbdavys), registracijos ir prisijungimų laikas.</li>'
                .'<li><strong>Darbuotojo profilis:</strong> vardas ir pavardė, telefono numeris, profilio nuotrauka ar avataras, pareigos ir patirtis, miestai, dominanti darbo trukmė, laisvos dienos, informacija apie save.</li>'
                .'<li><strong>Darbdavio duomenys:</strong> vardas ir pavardė, telefono numeris, darbovietės informacija (pavadinimas, adresas, tipas, aprašymas, logotipas, nuorodos), paskelbtos paieškos ir komanda.</li>'
                .'<li><strong>Veiklos duomenys:</strong> kandidatūros, GANG kontraktai, atvykimo patvirtinimai, atšaukimai ir jų priežastys, neatvykimo žymos, įvertinimai, kvietimai.</li>'
                .'<li><strong>Techniniai duomenys:</strong> prisijungimo kodai (saugomi tik užšifruoti), sesijos slapukas, IP adresas ir naršyklės informacija, užfiksuoti sutinkant su taisyklėmis, serverio žurnalai.</li></ul>'],
            ['tikslai', 'Kam naudojame duomenis ir kuo remiamės', '<ul>'
                .'<li><strong>Paskyrai sukurti ir platformai teikti</strong> (sutarties vykdymas, BDAR 6 str. 1 d. b p.): prisijungimas, profilis, paieškos, kandidatūros, kontraktai, pranešimai el. paštu ar SMS apie tinkamus pasiūlymus, kandidatūras ir pamainas.</li>'
                .'<li><strong>Pasitikėjimui ir saugumui užtikrinti</strong> (teisėtas interesas, 6 str. 1 d. f p.): įvertinimai, patikimumo rodikliai, piktnaudžiavimo prevencija, paskyrų blokavimas, sistemos apsauga.</li>'
                .'<li><strong>Sutikimui su taisyklėmis įrodyti</strong> (teisėtas interesas): taisyklių redakcija, sutikimo laikas, IP adresas ir naršyklės informacija.</li>'
                .'<li><strong>Teisės aktų reikalavimams vykdyti</strong> (teisinė prievolė, 6 str. 1 d. c p.), pavyzdžiui, atsakant į kompetentingų institucijų užklausas.</li>'
                .'<li><strong>Naujienoms siųsti</strong> – tik jei tam atskirai sutiksite (sutikimas, 6 str. 1 d. a p.). Sutikimą galėsite bet kada atšaukti.</li></ul>'
                .'<p>Duomenų neparduodame ir reklamos tikslais tretiesiems asmenims neperduodame.</p>'],
            ['kas-mato', 'Kas mato jūsų duomenis', '<ul>'
                .'<li>Darbuotojo profilį (vardą, nuotrauką, pareigas, patirtį, miestus, laisvas dienas, įvertinimus) mato prisijungę darbdaviai.</li>'
                .'<li>Darbovietės puslapis, jos adresas ir paskelbtos paieškos yra vieši.</li>'
                .'<li>Telefono numeris kitai šaliai parodomas tik po GANG kontrakto.</li>'
                .'<li>El. pašto adresas kitiems nariams nerodomas niekada.</li>'
                .'<li>Įvertinimai skelbiami nenurodant autoriaus.</li></ul>'],
            ['tvarkytojai', 'Paslaugų teikėjai', '<p>Duomenis patikime tik paslaugų teikėjams, be kurių platforma negalėtų veikti, ir tik tiek, kiek būtina:</p><ul>'
                .'<li><strong>Hostinger</strong> – svetainės ir duomenų bazės prieglobos paslaugos;</li>'
                .'<li><strong>Resend</strong> – el. laiškų siuntimas;</li>'
                .'<li><strong>Twilio</strong> – SMS pranešimų siuntimas, kai ši paslauga įjungta.</li></ul>'
                .'<p>Jei duomenys perduodami už Europos ekonominės erdvės ribų, tai daroma taikant BDAR numatytas apsaugos priemones: Europos Komisijos patvirtintas standartines sutarčių sąlygas arba sprendimą dėl tinkamumo.</p>'],
            ['terminai', 'Kiek laiko saugome', '<ul>'
                .'<li>Paskyros, profilio ir veiklos duomenis – kol turite paskyrą. Paskyrą ištrynus, duomenys ištrinami per 30 dienų.</li>'
                .'<li>Prisijungimo kodai galioja 15 minučių.</li>'
                .'<li>Sutikimo su taisyklėmis įrašus – kol turite paskyrą.</li>'
                .'<li>Serverio žurnalus – ne ilgiau, nei jie saugomi prieglobos paslaugų teikėjo sistemose saugumo tikslais.</li></ul>'],
            ['teises', 'Jūsų teisės', '<p>Turite teisę susipažinti su savo duomenimis, juos ištaisyti, ištrinti, apriboti jų tvarkymą, gauti juos perkeliamu formatu, nesutikti, kad duomenys būtų tvarkomi remiantis teisėtu interesu, ir bet kada atšaukti duotą sutikimą. Daugumą duomenų galite patys pakeisti savo profilyje.</p>'
                .'<p>Norėdami pasinaudoti teisėmis, rašykite '.$mail.' iš el. pašto adreso, kuriuo esate užsiregistravę. Atsakysime per 30 dienų. Jei manote, kad jūsų duomenys tvarkomi netinkamai, galite pateikti skundą Valstybinei duomenų apsaugos inspekcijai (<a href="https://vdai.lrv.lt" rel="noopener" target="_blank">vdai.lrv.lt</a>).</p>'],
            ['slapukai', 'Slapukai', '<p>Naudojame tik būtinuosius slapukus, be kurių svetainė neveiktų:</p><ul>'
                .'<li><strong>PHPSESSID</strong> – išlaiko jus prisijungusius ir apsaugo formas; galioja iki 60 dienų;</li>'
                .'<li><strong>gang_preview</strong> – kol svetainė neatidaryta, suteikia prieigą GANG komandai; galioja iki 90 dienų.</li></ul>'
                .'<p>Analitikos ir reklamos slapukų nenaudojame. Jei ateityje jų prireiktų, prieš juos įrašydami paprašysime jūsų sutikimo.</p>'],
            ['automatizuota', 'Automatizuoti sprendimai', '<p>Pranešimus apie paieškas siunčiame darbuotojams, kurių profilio pareigos, miestas ir patirtis atitinka paieškos reikalavimus. Tai padeda greičiau rasti tinkamus žmones, tačiau sprendimą, ką pakviesti dirbti, visada priima darbdavys, o ne sistema.</p>'],
            ['pakeitimai', 'Politikos pakeitimai', '<p>Pasikeitus duomenų tvarkymui, šią politiką atnaujinsime. Apie esminius pakeitimus informuosime el. paštu arba prisijungus prie GANG.</p>'],
        ]);
}
