<?php
require __DIR__ . '/../src/bootstrap.php';

kop('Privacyverklaring');
?>
<div class="tekst">
  <h1>Privacyverklaring</h1>
  <p class="melding info">Conceptversie. Laat deze tekst controleren en aanvullen voordat de site live gaat.</p>

  <h2>Wie zijn wij</h2>
  <p>[Bedrijfsnaam], [adres], KvK [nummer], is verantwoordelijk voor de verwerking van je persoonsgegevens via deze website.</p>

  <h2>Welke gegevens verwerken we</h2>
  <ul>
    <li>Naam, e-mailadres en (optioneel) telefoonnummer, om je account en reserveringen te beheren.</li>
    <li>Je wachtwoord, uitsluitend versleuteld (gehasht) opgeslagen.</li>
    <li>Je reserveringen: fiets, periode, afhaal- en terugbrenglocatie.</li>
    <li>Optioneel: de vertrek- en aankomstplaats van je fietstocht.</li>
    <li>Het IP-adres bij mislukte inlogpogingen, maximaal 24 uur, om misbruik te voorkomen.</li>
  </ul>

  <h2>Waarom</h2>
  <p>We gebruiken deze gegevens alleen om de huurovereenkomst uit te voeren en om contact met je op te nemen over je reservering.</p>

  <h2>Fietsroute en kaart (OpenStreetMap)</h2>
  <p>Vul je een vertrek- en aankomstplaats in, dan zoeken we de coördinaten van die plaatsen op via Nominatim en berekenen we de fietsroute via BRouter. Daarbij sturen wij alleen de plaatsnamen en coördinaten door, geen naam, e-mailadres of andere gegevens van jou. De kaartafbeeldingen worden in je browser geladen van de servers van OpenStreetMap; daarbij ontvangt de OpenStreetMap Foundation je IP-adres. Zie het <a href="https://osmfoundation.org/wiki/Privacy_Policy" target="_blank" rel="noopener">privacybeleid van OpenStreetMap</a>. [Controleren en aanpassen als er eigen of andere route- en kaartservers worden gebruikt.]</p>

  <h2>Hoe lang bewaren we ze</h2>
  <p>[Bewaartermijn invullen, bijvoorbeeld: tot [x] maanden na je laatste reservering, of zolang de wet dat vereist.]</p>

  <h2>Je rechten</h2>
  <p>Je kunt je gegevens inzien, laten corrigeren of laten verwijderen. Neem daarvoor contact op via [e-mailadres].</p>
</div>
<?php voet();
