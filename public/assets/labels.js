// Tekent een QR-code in elk element met data-qr (bibliotheek: qrcode-generator, MIT, zie qrcode/LICENSE.txt).
// Geen inline JavaScript i.v.m. de Content-Security-Policy.
document.addEventListener('DOMContentLoaded', function () {
  if (typeof qrcode === 'undefined') return;
  document.querySelectorAll('[data-qr]').forEach(function (el) {
    var qr = qrcode(0, 'M');           // 0 = automatisch formaat, M = ca. 15% foutcorrectie
    qr.addData(el.getAttribute('data-qr'));
    qr.make();
    el.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 2, scalable: true });
  });
  var knop = document.getElementById('printen');
  if (knop) knop.addEventListener('click', function () { window.print(); });
});
