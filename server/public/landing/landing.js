// Возможности на широком экране: слева пункты, справа скриншот выбранного.
// Без скрипта и на узком экране пункты идут подряд, каждый со своим скриншотом.
document.querySelectorAll('[data-tour]').forEach(function (tour) {
    var items = Array.prototype.slice.call(tour.querySelectorAll('.tour-item'));
    var wide = window.matchMedia('(min-width: 961px)');

    function sync() {
        items.forEach(function (item) {
            var expanded = !wide.matches || item.classList.contains('is-active');
            item.querySelector('.tour-tab').setAttribute('aria-expanded', expanded ? 'true' : 'false');
        });
    }

    items.forEach(function (item) {
        item.querySelector('.tour-text').addEventListener('click', function () {
            items.forEach(function (other) {
                other.classList.toggle('is-active', other === item);
            });
            sync();
        });
    });

    wide.addEventListener('change', sync);
    sync();
    tour.classList.add('is-ready');
});
