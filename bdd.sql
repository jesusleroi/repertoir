CREATE TABLE `absences_eleves` (
  `id` int(11) NOT NULL,
  `eleve_id` int(11) NOT NULL,
  `classe` varchar(100) NOT NULL,
  `date_absence` date NOT NULL,
  `heure_debut` time DEFAULT NULL,
  `heure_fin` time DEFAULT NULL,
  `type` enum('absence','retard') NOT NULL,
  `justifiee` tinyint(1) DEFAULT 0,
  `motif` text DEFAULT NULL,
  `piece_jointe` varchar(255) DEFAULT NULL,
  `signale_par` int(11) DEFAULT NULL,
  `date_creation` datetime DEFAULT current_timestamp(),
  `parents_notifies` tinyint(1) DEFAULT 0,
  `date_notification` datetime DEFAULT NULL,
  `date_fin_absence` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `absences_enseignants`
--

CREATE TABLE `absences_enseignants` (
  `id` int(11) NOT NULL,
  `enseignant_id` int(11) NOT NULL,
  `date_absence` date NOT NULL,
  `heure_debut` time DEFAULT NULL,
  `heure_fin` time DEFAULT NULL,
  `type` enum('absence','retard') NOT NULL,
  `motif` text DEFAULT NULL,
  `piece_jointe` varchar(255) DEFAULT NULL,
  `remplacant_id` int(11) DEFAULT NULL,
  `cours_annules` text DEFAULT NULL,
  `signale_par` int(11) DEFAULT NULL,
  `date_creation` datetime DEFAULT current_timestamp(),
  `date_fin_absence` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `actualites_infos`
--

CREATE TABLE `actualites_infos` (
  `id` int(11) NOT NULL,
  `titre` varchar(255) NOT NULL,
  `contenu` longtext NOT NULL,
  `type_actualite` enum('information','evenement','urgent','academique','administrative','celebration') NOT NULL DEFAULT 'information',
  `image` varchar(255) DEFAULT NULL,
  `auteur` varchar(100) NOT NULL,
  `date_creation` datetime DEFAULT current_timestamp(),
  `date_modification` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `date_publication` datetime DEFAULT NULL,
  `statut` enum('brouillon','publie','archive') NOT NULL DEFAULT 'brouillon',
  `vues` int(11) DEFAULT 0,
  `priorite` tinyint(4) DEFAULT 1,
  `tags` text DEFAULT NULL,
  `commentaires_actifs` tinyint(1) DEFAULT 1,
  `partage_actif` tinyint(1) DEFAULT 1,
  `reserve1` text DEFAULT NULL,
  `reserve2` text DEFAULT NULL,
  `reserve3` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `alertes_budgetaires`
--

CREATE TABLE `alertes_budgetaires` (
  `id` int(11) NOT NULL,
  `budget_id` int(11) NOT NULL,
  `type_alerte` enum('depassement','seuil_atteint','sous_consommation') NOT NULL,
  `pourcentage_realisation` decimal(8,2) NOT NULL,
  `montant_ecart` decimal(15,2) NOT NULL,
  `message_alerte` text NOT NULL,
  `est_traitee` tinyint(1) DEFAULT 0,
  `utilisateur_traite` varchar(100) DEFAULT NULL,
  `date_traitement` timestamp NULL DEFAULT NULL,
  `date_creation` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `appreciations_matieres`
--

CREATE TABLE `appreciations_matieres` (
  `id` int(11) NOT NULL,
  `bulletin_id` int(11) NOT NULL,
  `eleve_id` int(11) NOT NULL,
  `matiere` varchar(100) NOT NULL,
  `appreciation` text DEFAULT NULL,
  `enseignant_id` int(11) NOT NULL,
  `date_saisie` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `budgets`
--

CREATE TABLE `budgets` (
  `id` int(11) NOT NULL,
  `annee_scolaire` varchar(9) NOT NULL,
  `compte_id` int(11) NOT NULL,
  `categorie_id` int(11) DEFAULT NULL,
  `montant_prevu` decimal(10,2) NOT NULL,
  `montant_realise` decimal(10,2) DEFAULT 0.00,
  `pourcentage_ecart` decimal(8,2) DEFAULT 0.00,
  `alerte_seuil` decimal(8,2) DEFAULT 10.00,
  `responsable` varchar(100) DEFAULT NULL,
  `date_alerte` timestamp NULL DEFAULT NULL,
  `trimestre` enum('T1','T2','T3','annuel') DEFAULT 'annuel',
  `type_budget` enum('previsionnel','reel','rectificatif') DEFAULT 'previsionnel',
  `statut` enum('previsionnel','valide','cloture') DEFAULT 'previsionnel',
  `commentaires` text DEFAULT NULL,
  `date_creation` timestamp NOT NULL DEFAULT current_timestamp(),
  `date_modification` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `bulletins_valides`
--

CREATE TABLE `bulletins_valides` (
  `id` int(11) NOT NULL,
  `bulletin_id` int(11) NOT NULL,
  `eleve_id` int(11) NOT NULL,
  `classe_id` int(11) NOT NULL,
  `moyenne_generale` decimal(4,2) DEFAULT NULL,
  `appreciation_generale` text DEFAULT NULL,
  `statut` enum('brouillon','valide','publie') DEFAULT 'brouillon',
  `date_validation` datetime DEFAULT NULL,
  `administrateur_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `calendrier_scolaire`
--

CREATE TABLE `calendrier_scolaire` (
  `id` int(11) NOT NULL,
  `titre` varchar(255) NOT NULL,
  `type_periode` enum('periode_etude','conge','vacances','examen','evenement','prerentree','rentree_scolaire','fermeture_classes','jour_ferie','autre') NOT NULL,
  `date_debut` date NOT NULL,
  `date_fin` date NOT NULL,
  `description` text DEFAULT NULL,
  `couleur` varchar(7) DEFAULT '#3b82f6',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `categories_budget`
--

CREATE TABLE `categories_budget` (
  `id` int(11) NOT NULL,
  `nom_categorie` varchar(100) NOT NULL,
  `couleur_interface` varchar(7) DEFAULT '#3B82F6',
  `icone` varchar(50) DEFAULT 'fa-folder',
  `description` text DEFAULT NULL,
  `est_active` tinyint(1) DEFAULT 1,
  `ordre_affichage` int(11) DEFAULT 0,
  `date_creation` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `classes`
--

CREATE TABLE `classes` (
  `id` int(11) NOT NULL,
  `nom_classe` varchar(100) NOT NULL,
  `niveau` varchar(50) NOT NULL,
  `mat_coef_bareme` text NOT NULL,
  `date_creation` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `comptes_comptables`
--

CREATE TABLE `comptes_comptables` (
  `id` int(11) NOT NULL,
  `numero_compte` varchar(10) NOT NULL,
  `nom_compte` varchar(100) NOT NULL,
  `type_compte` enum('actif','passif','charge','produit') NOT NULL,
  `categorie` enum('banque','caisse','creances','dettes','immobilisations','stocks','charges_exploitation','produits_exploitation','charges_financieres','produits_financiers','charges_exceptionnelles','produits_exceptionnels') NOT NULL,
  `parent_compte_id` int(11) DEFAULT NULL,
  `est_actif` tinyint(1) DEFAULT 1,
  `date_creation` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `configuration_devise`
--

CREATE TABLE `configuration_devise` (
  `id` int(11) NOT NULL,
  `code_devise` varchar(3) NOT NULL DEFAULT 'FG',
  `nom_devise` varchar(50) NOT NULL DEFAULT 'Franc Guinéen',
  `symbole` varchar(10) NOT NULL DEFAULT 'FG',
  `position_symbole` enum('avant','apres') DEFAULT 'apres',
  `separateur_milliers` varchar(1) DEFAULT ' ',
  `separateur_decimales` varchar(1) DEFAULT ',',
  `nb_decimales` tinyint(4) DEFAULT 0,
  `est_active` tinyint(1) DEFAULT 1,
  `date_creation` timestamp NOT NULL DEFAULT current_timestamp(),
  `date_modification` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `creneaux_horaires`
--

CREATE TABLE `creneaux_horaires` (
  `id` int(11) NOT NULL,
  `nom` varchar(50) NOT NULL,
  `heure_debut` time NOT NULL,
  `heure_fin` time NOT NULL,
  `ordre_affichage` int(11) NOT NULL,
  `actif` tinyint(1) DEFAULT 1,
  `date_creation` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `devoirs`
--

CREATE TABLE `devoirs` (
  `id` int(11) NOT NULL,
  `titre` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `matiere` varchar(100) DEFAULT NULL,
  `classe` varchar(50) DEFAULT NULL,
  `enseignant_id` int(11) DEFAULT NULL,
  `date_creation` datetime DEFAULT current_timestamp(),
  `date_limite` datetime DEFAULT NULL,
  `fichier_enonce` varchar(500) DEFAULT NULL,
  `statut` enum('actif','archive') DEFAULT 'actif'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `ecritures_comptables`
--

CREATE TABLE `ecritures_comptables` (
  `id` int(11) NOT NULL,
  `numero_piece` varchar(20) NOT NULL,
  `date_ecriture` date NOT NULL,
  `libelle` text NOT NULL,
  `montant_total` decimal(10,2) NOT NULL,
  `type_operation` enum('recette','depense','virement','ecriture_comptable') NOT NULL,
  `statut` enum('brouillon','validee','cloturee') DEFAULT 'brouillon',
  `reference_externe` varchar(50) DEFAULT NULL,
  `utilisateur` varchar(100) NOT NULL,
  `date_creation` timestamp NOT NULL DEFAULT current_timestamp(),
  `date_validation` timestamp NULL DEFAULT NULL,
  `validee_par` varchar(100) DEFAULT NULL,
  `observations` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `eleves`
--

CREATE TABLE `eleves` (
  `id` int(11) NOT NULL,
  `classe_eleve` varchar(25) DEFAULT NULL,
  `nom_eleve` varchar(100) DEFAULT NULL,
  `prenom_eleve` varchar(100) DEFAULT NULL,
  `adresse_eleve` varchar(255) DEFAULT NULL,
  `date_de_naissance_eleve` date DEFAULT NULL,
  `lieu_de_naissance_eleve` varchar(100) DEFAULT NULL,
  `genre_eleve` enum('Masculin','Féminin') DEFAULT NULL,
  `matricule_eleve` varchar(100) DEFAULT NULL,
  `photo_eleve` varchar(255) DEFAULT NULL,
  `role_eleve` varchar(255) DEFAULT NULL,
  `telephone_eleve` varchar(100) DEFAULT NULL,
  `nom_parent` varchar(100) DEFAULT NULL,
  `prenom_parent` varchar(100) DEFAULT NULL,
  `profession_parent` varchar(100) DEFAULT NULL,
  `telephone_parent` varchar(100) DEFAULT NULL,
  `mot_de_passe_eleve` varchar(255) DEFAULT NULL,
  `mot_de_passe_parent` varchar(255) DEFAULT NULL,
  `reserve1` text DEFAULT NULL,
  `reserve2` text DEFAULT NULL,
  `reserve3` text DEFAULT NULL,
  `reserve4` text DEFAULT NULL,
  `date_creation` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `emplois_generes`
--

CREATE TABLE `emplois_generes` (
  `id` int(11) NOT NULL,
  `type` enum('classe','enseignant') NOT NULL,
  `identifiant` varchar(100) NOT NULL,
  `contenu_html` longtext NOT NULL,
  `contenu_pdf` longblob DEFAULT NULL,
  `hash_version` varchar(64) NOT NULL,
  `date_generation` datetime DEFAULT current_timestamp(),
  `date_modification` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `emploi_config`
--

CREATE TABLE `emploi_config` (
  `id` int(11) NOT NULL,
  `parametre` varchar(100) NOT NULL,
  `valeur` text NOT NULL,
  `description` text DEFAULT NULL,
  `date_modification` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `emploi_historique`
--

CREATE TABLE `emploi_historique` (
  `id` int(11) NOT NULL,
  `emploi_temps_id` int(11) DEFAULT NULL,
  `action` enum('CREATE','UPDATE','DELETE') NOT NULL,
  `ancienne_valeur` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `emploi_temps`
--

CREATE TABLE `emploi_temps` (
  `id` int(11) NOT NULL,
  `classe` varchar(50) NOT NULL,
  `jour` enum('Lundi','Mardi','Mercredi','Jeudi','Vendredi','Samedi') NOT NULL,
  `creneau_id` int(11) DEFAULT NULL,
  `matiere` varchar(100) NOT NULL,
  `enseignant_id` int(11) DEFAULT NULL,
  `salle` varchar(50) DEFAULT NULL,
  `type_cours` enum('Cours','TD','TP','Contrôle','Sport') DEFAULT 'Cours',
  `couleur` varchar(7) DEFAULT '#3B82F6',
  `actif` tinyint(1) DEFAULT 1,
  `date_creation` datetime DEFAULT current_timestamp(),
  `date_modification` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `heure_debut_custom` time DEFAULT NULL,
  `heure_fin_custom` time DEFAULT NULL,
  `heure_debut` time NOT NULL,
  `heure_fin` time NOT NULL,
  `type_element` enum('cours','pause') DEFAULT 'cours'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `exercices_comptables`
--

CREATE TABLE `exercices_comptables` (
  `id` int(11) NOT NULL,
  `annee_scolaire` varchar(9) NOT NULL,
  `date_debut` date NOT NULL,
  `date_fin` date NOT NULL,
  `statut` enum('ouvert','en_cloture','cloture') DEFAULT 'ouvert',
  `resultat_exercice` decimal(10,2) DEFAULT 0.00,
  `date_cloture` timestamp NULL DEFAULT NULL,
  `utilisateur_cloture` varchar(100) DEFAULT NULL,
  `date_creation` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `fonctions_application`
--

CREATE TABLE `fonctions_application` (
  `id` int(11) NOT NULL,
  `fonctions` text NOT NULL,
  `date_creation` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `frais_classes`
--

CREATE TABLE `frais_classes` (
  `id` int(11) NOT NULL,
  `annee_scolaire` varchar(9) NOT NULL,
  `classe_nom` varchar(100) NOT NULL,
  `scolarite_active` tinyint(1) DEFAULT 0,
  `scolarite_montant` decimal(10,2) DEFAULT 0.00,
  `assurance_active` tinyint(1) DEFAULT 0,
  `assurance_montant` decimal(10,2) DEFAULT 0.00,
  `apeae_active` tinyint(1) DEFAULT 0,
  `apeae_montant` decimal(10,2) DEFAULT 0.00,
  `cantine_active` tinyint(1) DEFAULT 0,
  `cantine_montant` decimal(10,2) DEFAULT 0.00,
  `bus_active` tinyint(1) DEFAULT 0,
  `bus_montant` decimal(10,2) DEFAULT 0.00,
  `fournitures_active` tinyint(1) DEFAULT 0,
  `fournitures_montant` decimal(10,2) DEFAULT 0.00,
  `date_creation` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `historique_budgets`
--

CREATE TABLE `historique_budgets` (
  `id` int(11) NOT NULL,
  `budget_id` int(11) NOT NULL,
  `action` enum('creation','modification','validation','cloture') NOT NULL,
  `ancien_montant` decimal(15,2) DEFAULT NULL,
  `nouveau_montant` decimal(15,2) DEFAULT NULL,
  `motif` text DEFAULT NULL,
  `utilisateur` varchar(100) NOT NULL,
  `date_action` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `informations_ecole`
--

CREATE TABLE `informations_ecole` (
  `id` int(11) NOT NULL,
  `nom_ecole` varchar(255) DEFAULT NULL,
  `nom_abrege` varchar(100) DEFAULT NULL,
  `type_ecole` varchar(100) DEFAULT NULL,
  `tel_ecole` varchar(20) DEFAULT NULL,
  `mail_ecole` varchar(255) DEFAULT NULL,
  `ville_ecole` varchar(100) DEFAULT NULL,
  `adresse_ecole` text DEFAULT NULL,
  `devise_ecole` text DEFAULT NULL,
  `date_creation_ecole` datetime DEFAULT current_timestamp(),
  `logo_ecole` varchar(255) DEFAULT NULL,
  `entete_ecole` text DEFAULT NULL,
  `pied_de_page_ecole` text DEFAULT NULL,
  `preference_ecole` text DEFAULT NULL,
  `etape_inscription` int(11) DEFAULT 1,
  `reserve1` text DEFAULT NULL,
  `reserve2` text DEFAULT NULL,
  `reserve3` text DEFAULT NULL,
  `reserve4` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `lignes_budget`
--

CREATE TABLE `lignes_budget` (
  `id` int(11) NOT NULL,
  `budget_id` int(11) NOT NULL,
  `description` varchar(255) NOT NULL,
  `montant_prevu` decimal(15,2) NOT NULL DEFAULT 0.00,
  `montant_realise` decimal(15,2) NOT NULL DEFAULT 0.00,
  `mois_prevision` tinyint(4) NOT NULL,
  `statut` enum('actif','suspendu','termine') DEFAULT 'actif',
  `date_creation` timestamp NOT NULL DEFAULT current_timestamp(),
  `date_modification` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `lignes_ecritures`
--

CREATE TABLE `lignes_ecritures` (
  `id` int(11) NOT NULL,
  `ecriture_id` int(11) NOT NULL,
  `compte_id` int(11) NOT NULL,
  `libelle` varchar(255) NOT NULL,
  `debit` decimal(10,2) DEFAULT 0.00,
  `credit` decimal(10,2) DEFAULT 0.00,
  `date_creation` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `matieres`
--

CREATE TABLE `matieres` (
  `id` int(11) NOT NULL,
  `nom_matiere` varchar(255) NOT NULL,
  `code_matiere` varchar(50) NOT NULL,
  `reserve1` text DEFAULT NULL,
  `reserve2` text DEFAULT NULL,
  `reserve3` text DEFAULT NULL,
  `reserve4` text DEFAULT NULL,
  `date_creation` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `messages`
--

CREATE TABLE `messages` (
  `id` int(11) NOT NULL,
  `sender_id` int(11) DEFAULT NULL,
  `sender_nom` varchar(100) DEFAULT NULL,
  `sender_prenom` varchar(100) DEFAULT NULL,
  `destinataire_type` varchar(30) NOT NULL,
  `eleve_id` int(11) DEFAULT NULL,
  `classe_id` int(11) DEFAULT NULL,
  `enseignant_id` int(11) DEFAULT NULL,
  `sujet` varchar(255) NOT NULL,
  `contenu` text NOT NULL,
  `date_envoi` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `message_whatsapp_log`
--

CREATE TABLE `message_whatsapp_log` (
  `id` int(11) NOT NULL,
  `message_id` int(11) DEFAULT NULL,
  `destinataire_type` enum('parent','enseignant','autre') NOT NULL,
  `cible_id` int(11) DEFAULT NULL,
  `telephone` varchar(30) NOT NULL,
  `statut` enum('sent','failed') NOT NULL,
  `api_message_id` varchar(100) DEFAULT NULL,
  `erreur` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `moyens_paiement`
--

CREATE TABLE `moyens_paiement` (
  `id` int(11) NOT NULL,
  `nom` varchar(50) NOT NULL,
  `type` enum('especes','cheque','virement','carte_bancaire','prelevement','Orange Money','Mobile Money','autre') NOT NULL,
  `compte_associe_id` int(11) DEFAULT NULL,
  `est_actif` tinyint(1) DEFAULT 1,
  `date_creation` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `notes`
--

CREATE TABLE `notes` (
  `id` int(11) NOT NULL,
  `bulletin_id` int(11) NOT NULL,
  `classe_id` int(11) NOT NULL,
  `eleve_id` int(11) NOT NULL,
  `matiere` varchar(100) NOT NULL,
  `colonne_index` int(11) NOT NULL,
  `note` decimal(4,2) DEFAULT NULL,
  `date_saisie` datetime DEFAULT current_timestamp(),
  `enseignant_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `paiements_eleves`
--

CREATE TABLE `paiements_eleves` (
  `id` int(11) NOT NULL,
  `annee_scolaire` varchar(9) NOT NULL,
  `eleve_id` int(11) NOT NULL,
  `classe_nom` varchar(100) NOT NULL,
  `type_frais` enum('scolarite','assurance','apeae','cantine','bus','fournitures') NOT NULL,
  `montant` decimal(10,2) NOT NULL,
  `date_paiement` date NOT NULL,
  `moyen_paiement_id` int(11) DEFAULT NULL,
  `reference` varchar(100) DEFAULT NULL,
  `statut` enum('valide','en_attente','annule') DEFAULT 'valide',
  `utilisateur` varchar(100) DEFAULT NULL,
  `date_creation` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `programmation_bulletin`
--

CREATE TABLE `programmation_bulletin` (
  `id` int(11) NOT NULL,
  `nom_bulletin` varchar(255) NOT NULL COMMENT 'Nom du bulletin (ex: Bulletin du premier trimestre)',
  `date_debut` date NOT NULL COMMENT 'Date de début pour la rentrée des notes',
  `date_fin` date NOT NULL COMMENT 'Date de fin pour la rentrée des notes',
  `classes_concernees` text NOT NULL COMMENT 'JSON des classes sélectionnées',
  `colonnes_notes` text NOT NULL COMMENT 'JSON des colonnes de notes (type et coefficient)',
  `date_creation` datetime NOT NULL DEFAULT current_timestamp(),
  `statut` enum('actif','inactif','termine') NOT NULL DEFAULT 'actif',
  `created_by` int(11) DEFAULT NULL COMMENT 'ID de l utilisateur qui a créé le bulletin'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Table de programmation des bulletins de notes';

-- --------------------------------------------------------

--
-- Structure de la table `rapprochements_bancaires`
--

CREATE TABLE `rapprochements_bancaires` (
  `id` int(11) NOT NULL,
  `compte_id` int(11) NOT NULL,
  `date_rapprochement` date NOT NULL,
  `solde_comptable` decimal(10,2) NOT NULL,
  `solde_bancaire` decimal(10,2) NOT NULL,
  `ecart` decimal(10,2) GENERATED ALWAYS AS (`solde_bancaire` - `solde_comptable`) STORED,
  `statut` enum('en_cours','equilibre','avec_ecart') DEFAULT 'en_cours',
  `observations` text DEFAULT NULL,
  `utilisateur` varchar(100) NOT NULL,
  `date_creation` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `rendus`
--

CREATE TABLE `rendus` (
  `id` int(11) NOT NULL,
  `devoir_id` int(11) NOT NULL,
  `eleve_id` int(11) NOT NULL,
  `fichier_rendu` varchar(255) DEFAULT NULL,
  `nom_fichier_original` varchar(255) DEFAULT NULL,
  `taille_fichier` int(11) DEFAULT NULL,
  `statut` enum('non_commence','telecharge','en_cours','rendu','corrige','en_retard') DEFAULT 'non_commence',
  `date_telechargement` datetime DEFAULT NULL,
  `date_rendu` datetime DEFAULT NULL,
  `date_modification` datetime DEFAULT NULL,
  `note` decimal(4,2) DEFAULT NULL,
  `commentaire_enseignant` text DEFAULT NULL,
  `date_correction` datetime DEFAULT NULL,
  `date_creation` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `roles`
--

CREATE TABLE `roles` (
  `id` int(11) NOT NULL,
  `code_role` varchar(50) NOT NULL,
  `executants` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `telechargements_devoirs`
--

CREATE TABLE `telechargements_devoirs` (
  `id` int(11) NOT NULL,
  `devoir_id` int(11) NOT NULL,
  `eleve_id` int(11) NOT NULL,
  `date_telechargement` datetime DEFAULT current_timestamp(),
  `ip_adresse` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `tresorerie`
--

CREATE TABLE `tresorerie` (
  `id` int(11) NOT NULL,
  `compte_id` int(11) NOT NULL,
  `date_operation` date NOT NULL,
  `libelle` varchar(255) NOT NULL,
  `montant` decimal(10,2) NOT NULL,
  `type_operation` enum('entree','sortie') NOT NULL,
  `moyen_paiement_id` int(11) NOT NULL,
  `numero_piece` varchar(50) DEFAULT NULL,
  `beneficiaire` varchar(100) DEFAULT NULL,
  `categorie` enum('scolarite','subventions','donations','fournitures','salaires','charges','investissements','autre') NOT NULL,
  `ecriture_id` int(11) DEFAULT NULL,
  `statut` enum('prevue','realisee','rapprochee') DEFAULT 'realisee',
  `utilisateur` varchar(100) NOT NULL,
  `date_creation` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `utilisateurs`
--

CREATE TABLE `utilisateurs` (
  `id` int(11) NOT NULL,
  `nom_u` varchar(100) DEFAULT NULL,
  `prenom_u` varchar(100) DEFAULT NULL,
  `mail_u` varchar(255) DEFAULT NULL,
  `tel_u` varchar(20) DEFAULT NULL,
  `mot_de_passe_u` varchar(255) DEFAULT NULL,
  `fonction_u` varchar(100) DEFAULT NULL,
  `role_u` varchar(50) DEFAULT 'super_admin',
  `matiere_niveau_u` text DEFAULT NULL,
  `pp_u` varchar(255) DEFAULT NULL,
  `date_creation` datetime DEFAULT current_timestamp(),
  `reserve1` text DEFAULT NULL,
  `reserve2` text DEFAULT NULL,
  `reserve3` text DEFAULT NULL,
  `reserve4` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


--
-- Contraintes pour la table `alertes_budgetaires`
--
ALTER TABLE `alertes_budgetaires`
  ADD CONSTRAINT `alertes_budgetaires_ibfk_1` FOREIGN KEY (`budget_id`) REFERENCES `budgets` (`id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `budgets`
--
ALTER TABLE `budgets`
  ADD CONSTRAINT `budgets_ibfk_1` FOREIGN KEY (`compte_id`) REFERENCES `comptes_comptables` (`id`),
  ADD CONSTRAINT `budgets_ibfk_2` FOREIGN KEY (`categorie_id`) REFERENCES `categories_budget` (`id`);

--
-- Contraintes pour la table `comptes_comptables`
--
ALTER TABLE `comptes_comptables`
  ADD CONSTRAINT `comptes_comptables_ibfk_1` FOREIGN KEY (`parent_compte_id`) REFERENCES `comptes_comptables` (`id`);

--
-- Contraintes pour la table `emploi_temps`
--
ALTER TABLE `emploi_temps`
  ADD CONSTRAINT `emploi_temps_ibfk_1` FOREIGN KEY (`creneau_id`) REFERENCES `creneaux_horaires` (`id`),
  ADD CONSTRAINT `emploi_temps_ibfk_2` FOREIGN KEY (`enseignant_id`) REFERENCES `utilisateurs` (`id`);

--
-- Contraintes pour la table `historique_budgets`
--
ALTER TABLE `historique_budgets`
  ADD CONSTRAINT `historique_budgets_ibfk_1` FOREIGN KEY (`budget_id`) REFERENCES `budgets` (`id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `lignes_budget`
--
ALTER TABLE `lignes_budget`
  ADD CONSTRAINT `lignes_budget_ibfk_1` FOREIGN KEY (`budget_id`) REFERENCES `budgets` (`id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `lignes_ecritures`
--
ALTER TABLE `lignes_ecritures`
  ADD CONSTRAINT `lignes_ecritures_ibfk_1` FOREIGN KEY (`ecriture_id`) REFERENCES `ecritures_comptables` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `lignes_ecritures_ibfk_2` FOREIGN KEY (`compte_id`) REFERENCES `comptes_comptables` (`id`);

--
-- Contraintes pour la table `moyens_paiement`
--
ALTER TABLE `moyens_paiement`
  ADD CONSTRAINT `moyens_paiement_ibfk_1` FOREIGN KEY (`compte_associe_id`) REFERENCES `comptes_comptables` (`id`);

--
-- Contraintes pour la table `paiements_eleves`
--
ALTER TABLE `paiements_eleves`
  ADD CONSTRAINT `fk_paiement_eleve` FOREIGN KEY (`eleve_id`) REFERENCES `eleves` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_paiement_moyen` FOREIGN KEY (`moyen_paiement_id`) REFERENCES `moyens_paiement` (`id`);

--
-- Contraintes pour la table `rapprochements_bancaires`
--
ALTER TABLE `rapprochements_bancaires`
  ADD CONSTRAINT `rapprochements_bancaires_ibfk_1` FOREIGN KEY (`compte_id`) REFERENCES `comptes_comptables` (`id`);

--
-- Contraintes pour la table `rendus`
--
ALTER TABLE `rendus`
  ADD CONSTRAINT `rendus_ibfk_1` FOREIGN KEY (`devoir_id`) REFERENCES `devoirs` (`id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `telechargements_devoirs`
--
ALTER TABLE `telechargements_devoirs`
  ADD CONSTRAINT `telechargements_devoirs_ibfk_1` FOREIGN KEY (`devoir_id`) REFERENCES `devoirs` (`id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `tresorerie`
--
ALTER TABLE `tresorerie`
  ADD CONSTRAINT `tresorerie_ibfk_1` FOREIGN KEY (`compte_id`) REFERENCES `comptes_comptables` (`id`),
  ADD CONSTRAINT `tresorerie_ibfk_2` FOREIGN KEY (`moyen_paiement_id`) REFERENCES `moyens_paiement` (`id`),
  ADD CONSTRAINT `tresorerie_ibfk_3` FOREIGN KEY (`ecriture_id`) REFERENCES `ecritures_comptables` (`id`);
COMMIT;
