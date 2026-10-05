-- La table des notes du carnet.
--
-- Ce fichier est une MIGRATION : un changement de la structure de la base.
-- « wazi db:migrate » l'applique une fois, et s'en souvient.
--
-- Ce SQL est écrit pour SQLite, la base de la démonstration.

CREATE TABLE notes (
    -- Un numéro que la base donne elle-même à chaque note.
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    auteur VARCHAR(80) NOT NULL,
    texte VARCHAR(280) NOT NULL,
    couleur VARCHAR(20) NOT NULL DEFAULT 'neutre',
    -- SQLite n'a pas de type booléen : 0 pour non, 1 pour oui.
    importante INTEGER NOT NULL DEFAULT 0,
    -- La date de création, à l'heure universelle : 2026-10-05 09:30:00
    creee_le VARCHAR(19) NOT NULL
);

-- Toutes les requêtes du carnet cherchent par auteur : cet index les accélère.
CREATE INDEX notes_auteur ON notes (auteur);
