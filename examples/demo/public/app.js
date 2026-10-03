// Le script de la page « Mes notes » : marquer une note comme importante
// sans recharger la page.
//
// Il est servi par le site lui-même : la politique de sécurité (CSP) de Wazi
// autorise les scripts du site, et refuse ceux qui viendraient d'ailleurs ou
// qui seraient écrits dans la page par un visiteur.

// Le jeton de protection, écrit dans la page par le template (voir liste.kioo).
const jeton = document.querySelector('meta[name="jeton"]').content;

for (const note of document.querySelectorAll('[data-note]')) {
    const bouton = note.querySelector('.etoile');

    // Le bouton est caché dans le HTML : il n'apparaît que si ce script fonctionne.
    bouton.hidden = false;

    bouton.addEventListener('click', async () => {
        const reponse = await fetch('/notes/' + note.dataset.note + '/importante', {
            method: 'PATCH',
            // Sans cet en-tête, Wazi refuse la requête (403) : rien ne prouverait
            // qu'elle vient d'une page de ce site.
            headers: { 'X-CSRF-Token': jeton },
        });

        if (!reponse.ok) {
            bouton.textContent = 'Échec (' + reponse.status + ')';
            return;
        }

        const { importante } = await reponse.json();

        note.classList.toggle('importante', importante);
        bouton.setAttribute('aria-pressed', importante ? 'true' : 'false');
        compter();
    });
}

function compter() {
    const notes = document.querySelectorAll('[data-note]').length;
    const importantes = document.querySelectorAll('[data-note].importante').length;

    document.getElementById('compte').textContent = notes + ' note(s), dont ' + importantes + ' importante(s).';
}
