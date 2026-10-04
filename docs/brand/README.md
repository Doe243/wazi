# Identité visuelle de Wazi et Kioo

Piste retenue le 3 octobre 2026 : **« Verre et lumière »**. Wazi signifie « clair » et Kioo « vitre » : l'identité montre ce qu'on voit à travers, rien de caché.

## Les logos

| Fichier | Usage |
| --- | --- |
| [`wazi-mark.svg`](wazi-mark.svg) | Le signe de Wazi, sur fond clair |
| [`wazi-mark-dark.svg`](wazi-mark-dark.svg) | Le signe de Wazi, sur fond sombre |
| [`wazi-icon.svg`](wazi-icon.svg) | L'icône carrée : onglet du navigateur, avatar |
| [`kioo-mark.svg`](kioo-mark.svg) | Le signe de Kioo, sur fond clair |
| [`kioo-mark-dark.svg`](kioo-mark-dark.svg) | Le signe de Kioo, sur fond sombre |

**Le signe de Wazi** : deux panneaux de verre qui se recouvrent et forment un W. Le panneau de gauche est opaque, celui de droite translucide : on voit à travers.

**Le signe de Kioo** : une vitre carrée aux coins arrondis, avec deux reflets.

**Le nom** s'écrit en minuscules à côté du signe, en Sora : `wazi` en graisse 700, `kioo` en graisse 600, avec un interlettrage légèrement resserré (-0,03 em et -0,02 em). Dans une phrase, on écrit « Wazi » et « Kioo » avec une majuscule.

À respecter :
- garder autour du signe un espace libre au moins égal à l'épaisseur de son trait ;
- ne pas changer ses couleurs, ne pas l'incliner, ne pas lui ajouter d'ombre ni de dégradé ;
- sur une photo ou un fond chargé, utiliser l'icône carrée.

## Les couleurs

| Nom | Valeur | Usage |
| --- | --- | --- |
| Nuit d'eau | `#0D2B30` | Texte, fonds sombres, blocs de code |
| Verre profond | `#0B6E70` | Liens, boutons, éléments actifs |
| Lagon | `#12A3A0` | Accent graphique : logo, traits, pastilles |
| Lagon clair | `#5FD6D2` | Accent sur fond sombre |
| Buée | `#D6EEEC` | Fonds légers, surbrillance |
| Fond | `#F3F8F8` | Fond de page |
| Bordure | `#C5DAD9` | Traits et contours |
| Texte secondaire | `#3F5B5F` | Légendes, texte d'accompagnement |
| Miel | `#F2C879` | Second accent, à petite dose : textes entre guillemets dans le code sur fond sombre |
| Erreur | `#B4232A` | Messages d'erreur ; `#8E1B21` sur fond `#FBE9EA` pour une pastille |

Contrastes à tenir (texte lisible par tous) :
- le texte courant se met en Nuit d'eau ou en Texte secondaire sur Fond ou sur blanc ;
- **Lagon ne sert jamais de couleur de texte sur fond clair** : il n'est pas assez contrasté. Pour un lien ou un bouton, c'est Verre profond ;
- sur fond Nuit d'eau, le texte est en Fond (`#F3F8F8`) et l'accent en Lagon clair.

### Le thème sombre

Chaque surface de Wazi existe en clair et en sombre, et suit le réglage du visiteur (`prefers-color-scheme`). Les couleurs portent un **rôle** ; le thème sombre ne change que la valeur de chaque rôle.

| Rôle | Clair | Sombre |
| --- | --- | --- |
| Fond de page | `#F3F8F8` | `#071C20` |
| Carte | `#FFFFFF` | `#0D2B30` |
| Texte | `#0D2B30` | `#F3F8F8` |
| Texte secondaire | `#3F5B5F` | `#A9C4C3` |
| Trait | `#C5DAD9` | `#1F4A50` |
| Accent (liens, libellés, éléments actifs) | `#0B6E70` | `#5FD6D2` |
| Fond d'une pastille | `#D6EEEC` | `#12393F` |

En CSS, ces rôles s'écrivent une fois, en variables (`--fond`, `--carte`, `--texte`, `--second`, `--trait`, `--accent`, `--buee`) : aucune couleur n'est écrite en dur ailleurs dans une feuille de style.

## La typographie

| Police | Usage | Graisses |
| --- | --- | --- |
| **Sora** | Titres et texte | 400, 600, 700 |
| **JetBrains Mono** | Code | 400, 600 |

Les deux sont libres et gratuites (licence SIL Open Font, disponibles sur Google Fonts).

Là où aucune police ne peut être chargée (les pages d'erreur de Wazi, dont la politique de sécurité interdit toute ressource extérieure), on utilise les polices du système : `system-ui, sans-serif` pour le texte, `ui-monospace, monospace` pour le code.

## Les formes

- **Coins arrondis :** 20 px pour la carte principale d'une page, 16 px pour une carte, 10 px pour un bouton, 6 à 8 px pour un extrait de code ; une pastille est entièrement arrondie.
- **Traits** fins (1 px), dans la couleur du rôle « Trait ».
- **Espacements :** une seule échelle, en multiples de 4 px : 4, 8, 12, 16, 24, 32, 44. On ne choisit pas une valeur entre deux.
- **Relief :** une seule ombre, large et très douce, sous la carte principale d'une page (`0 18px 48px -24px`). Rien d'autre ne porte d'ombre.
- **Lumière :** le fond d'une page peut porter deux halos de la couleur Lagon, très légers (13 à 16 % d'opacité), en haut à droite et en bas à gauche. C'est le seul dégradé de l'identité : ni bouton, ni carte, ni texte en dégradé.
- **Largeur de lecture :** un paragraphe ne dépasse pas 60 caractères par ligne.
- Beaucoup d'air : l'identité doit rester légère.

## Les libellés et les pastilles

- Un **libellé** (le nom d'un champ, d'une rubrique) s'écrit en petites capitales : 12 px, graisse 600, interlettrage élargi, couleur d'accent.
- Une **pastille** (un code d'erreur, un état) s'écrit en police de code, 13 px, graisse 600, couleur d'accent sur son fond de pastille.
- Un **chemin de fichier**, un nom de classe ou une référence s'écrit en police de code.

## L'accessibilité

- Tout texte tient un contraste d'au moins 4,5 pour 1 avec son fond, en clair comme en sombre.
- Une information ne passe jamais par la couleur seule : une erreur a aussi un mot, une icône ou un libellé.
- Chaque page a une zone principale (`<main>`) et un seul grand titre (`<h1>`).
- Tout fonctionne au clavier, et l'élément actif se voit (contour de la couleur d'accent).
- Les pages se lisent sur un écran de 320 px de large sans défilement horizontal.

## Le ton

Celui des messages d'erreur de Wazi : on dit ce qui s'est passé, pourquoi, et comment corriger. Des phrases courtes, des mots simples, le vouvoiement. Pas de jargon sans l'expliquer, pas de superlatifs.

La phrase de Wazi : **« Codez avec l'IA, comprenez avec Wazi. »**

## Ce que l'identité ne fait pas

Les pages d'erreur que Wazi affiche aux visiteurs d'un site **ne portent ni logo ni nom** : annoncer le framework utilisé renseignerait quelqu'un qui cherche une faille. Elles reprennent les couleurs, les formes et le thème sombre, rien de plus.
