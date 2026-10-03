// Le script du site. Tout ce qu'il apporte est un confort : sans JavaScript,
// chaque page fonctionne quand même (on épingle depuis la page de la note, la
// suppression est immédiate, le thème suit celui de l'appareil).
//
// Il est servi par le site lui-même : la politique de sécurité (CSP) de Wazi
// autorise les scripts du site, et refuse ceux qui viendraient d'ailleurs ou
// qui seraient écrits dans la page par un visiteur.

// --- Le thème, clair ou sombre ----------------------------------------------

const boutonTheme = document.getElementById('theme');

if (boutonTheme) {
    // Le bouton est caché dans le HTML : il n'apparaît que si ce script fonctionne.
    boutonTheme.hidden = false;

    boutonTheme.addEventListener('click', () => {
        const racine = document.documentElement;
        const actuel = racine.dataset.theme ?? (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
        const suivant = actuel === 'dark' ? 'light' : 'dark';

        racine.dataset.theme = suivant;

        try {
            localStorage.setItem('theme', suivant);
        } catch {
            // Stockage interdit : le choix vaudra pour cette page seulement.
        }
    });
}

// --- Le nombre de caractères restants -----------------------------------------

for (const zone of document.querySelectorAll('textarea[data-compteur]')) {
    const affichage = document.getElementById(zone.dataset.compteur);
    const maximum = zone.maxLength;

    const compter = () => {
        const reste = maximum - zone.value.length;

        affichage.textContent = zone.value.length + ' / ' + maximum;
        affichage.classList.toggle('presque', reste <= 20);
    };

    zone.addEventListener('input', compter);

    if (zone.value !== '') {
        compter();
    }
}

// --- Confirmer avant de supprimer -----------------------------------------------

const fenetre = document.getElementById('confirmation');

if (fenetre && typeof fenetre.showModal === 'function') {
    let formulaireEnAttente = null;

    for (const formulaire of document.querySelectorAll('form[data-confirmer]')) {
        formulaire.addEventListener('submit', (evenement) => {
            // Le second passage, après « Supprimer » : on laisse partir le formulaire.
            if (formulaire === formulaireEnAttente) {
                return;
            }

            evenement.preventDefault();
            formulaireEnAttente = formulaire;
            fenetre.showModal();
        });
    }

    for (const bouton of fenetre.querySelectorAll('button')) {
        bouton.addEventListener('click', () => fenetre.close(bouton.value));
    }

    fenetre.addEventListener('close', () => {
        if (fenetre.returnValue === 'oui' && formulaireEnAttente) {
            // requestSubmit() envoie le formulaire comme l'aurait fait un clic :
            // avec son champ caché, donc avec le jeton de protection.
            formulaireEnAttente.requestSubmit();
        } else {
            formulaireEnAttente = null;
        }

        fenetre.returnValue = '';
    });
}

// --- Épingler une note sans recharger la page -------------------------------------

// Le jeton de protection, écrit dans la page par le template (voir liste.kioo).
const jeton = document.querySelector('meta[name="jeton"]')?.content;

if (jeton) {
    for (const note of document.querySelectorAll('[data-note]')) {
        const bouton = note.querySelector('.epingle');

        bouton.hidden = false;

        bouton.addEventListener('click', async () => {
            const reponse = await fetch('/notes/' + note.dataset.note + '/importante', {
                method: 'PATCH',
                // Sans cet en-tête, Wazi refuse la requête (403) : rien ne prouverait
                // qu'elle vient d'une page de ce site.
                headers: { 'X-CSRF-Token': jeton },
            });

            if (!reponse.ok) {
                // Session expirée, note supprimée dans un autre onglet... : on recharge.
                location.reload();
                return;
            }

            const { importante } = await reponse.json();

            note.classList.toggle('importante', importante);
            bouton.setAttribute('aria-pressed', importante ? 'true' : 'false');

            // Le compteur de l'en-tête : une épinglée de plus, ou de moins.
            const compte = document.getElementById('compte');

            compte.textContent = compte.textContent.replace(
                /dont (\d+)/,
                (texte, nombre) => 'dont ' + (Number(nombre) + (importante ? 1 : -1)),
            );
        });
    }
}
