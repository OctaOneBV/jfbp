// Toont de fietsroute op een OpenStreetMap-kaart (Leaflet).
// De routelijn staat als JSON in <script type="application/json" id="routedata">,
// zodat er geen inline JavaScript nodig is (Content-Security-Policy).
document.addEventListener('DOMContentLoaded', function () {
  var el = document.getElementById('kaart');
  var bron = document.getElementById('routedata');
  if (!el || !bron || typeof L === 'undefined') return;

  var data;
  try { data = JSON.parse(bron.textContent); } catch (e) { return; }
  if (!data.lijn || data.lijn.length < 2) return;

  var kaart = L.map(el, { scrollWheelZoom: false });
  L.tileLayer(el.dataset.tegels, {
    maxZoom: 19,
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>-bijdragers'
  }).addTo(kaart);

  // Witte rand onder de lijn voor contrast, daarboven de route in huisstijlkleur.
  L.polyline(data.lijn, { color: '#ffffff', weight: 8, opacity: 0.9 }).addTo(kaart);
  var route = L.polyline(data.lijn, { color: '#11357a', weight: 5, opacity: 0.95 }).addTo(kaart);

  // Tooltips als tekstknoop: plaatsnamen zijn invoer van de gebruiker en mogen geen HTML worden.
  function tekst(t) { var s = document.createElement('span'); s.textContent = t; return s; }

  var start = data.lijn[0];
  var eind = data.lijn[data.lijn.length - 1];
  L.circleMarker(start, { radius: 9, color: '#11357a', weight: 3, fillColor: '#00afef', fillOpacity: 1 })
    .addTo(kaart).bindTooltip(tekst('Vertrek: ' + data.van), { direction: 'top' });
  L.marker(eind).addTo(kaart).bindTooltip(tekst('Aankomst: ' + data.naar), { direction: 'top' });

  kaart.fitBounds(route.getBounds(), { padding: [24, 24] });
  // Scrollen over de pagina mag de kaart niet laten zoomen; na een klik wel.
  kaart.once('click', function () { kaart.scrollWheelZoom.enable(); });
});
