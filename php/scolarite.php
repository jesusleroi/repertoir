<?php
// scolarite.php - Gestion des frais et paiements de scolarité
session_start();
require_once 'connexion_bdd.php';

// Autorisations: super_admin, Direction, Secrétaire, Comptable
if (!isset($_SESSION['utilisateur_connecte']) ||
    ($_SESSION['utilisateur_connecte']['role_u'] !== 'super_admin' &&
     !in_array($_SESSION['utilisateur_connecte']['fonction_u'], ['Directeur','Directeur Adjoint','DE','Secrétaire','Comptable']))) {
    header('Location: connexion.php');
    exit();
}

// Connexion BD
try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=" . $_SESSION['base_de_donnees'] . ";charset=utf8mb4",
        $username,
        $password
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
} catch (PDOException $e) {
    die("Erreur de connexion à la base de données: " . $e->getMessage());
}

// Utilitaires
function formatFG($montant, $avecDevise = true) {
    if ($montant === null || $montant === '' || !is_numeric($montant)) return $avecDevise ? '0 FG' : '0';
    $v = (float)$montant;
    $s = number_format($v, 0, ',', ' ');
    return $avecDevise ? $s . ' FG' : $s;
}

$utilisateur_nom = $_SESSION['utilisateur_connecte']['prenom_u'] . ' ' . $_SESSION['utilisateur_connecte']['nom_u'];

// Informations de l'école (depuis la table informations_ecole)
$ecole_info = [
    'nom_ecole' => 'Votre Établissement',
    'nom_abrege' => '',
    'adresse_ecole' => 'Adresse de l\'établissement',
    'ville_ecole' => '',
    'tel_ecole' => 'Téléphone',
    'mail_ecole' => 'Email',
    'site_web' => '',
    'logo_ecole' => '',
    'entete_ecole' => '',
    'pied_de_page_ecole' => ''
];
try {
    $stmt = $pdo->query("SELECT * FROM informations_ecole ORDER BY id DESC LIMIT 1");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        foreach (['nom_ecole','nom_abrege','adresse_ecole','ville_ecole','tel_ecole','mail_ecole','site_web','logo_ecole','entete_ecole','pied_de_page_ecole'] as $k) {
            if (isset($row[$k]) && $row[$k] !== null && $row[$k] !== '') {
                $ecole_info[$k] = $row[$k];
            }
        }
    }
} catch (Exception $e) {
    // on garde les valeurs par défaut si la table n'existe pas
}

// Déterminer l'année scolaire courante
$annee_courante = '2024-2025';
try {
    $stmt = $pdo->query("SELECT annee_scolaire FROM exercices_comptables ORDER BY id DESC LIMIT 1");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && !empty($row['annee_scolaire'])) {
        $annee_courante = $row['annee_scolaire'];
    }
} catch (Exception $e) {}

// Création tables si n'existent pas
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS frais_classes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            annee_scolaire VARCHAR(9) NOT NULL,
            classe_nom VARCHAR(100) NOT NULL,
            scolarite_active TINYINT(1) DEFAULT 0,
            scolarite_montant DECIMAL(10,2) DEFAULT 0,
            assurance_active TINYINT(1) DEFAULT 0,
            assurance_montant DECIMAL(10,2) DEFAULT 0,
            apeae_active TINYINT(1) DEFAULT 0,
            apeae_montant DECIMAL(10,2) DEFAULT 0,
            cantine_active TINYINT(1) DEFAULT 0,
            cantine_montant DECIMAL(10,2) DEFAULT 0,
            bus_active TINYINT(1) DEFAULT 0,
            bus_montant DECIMAL(10,2) DEFAULT 0,
            fournitures_active TINYINT(1) DEFAULT 0,
            fournitures_montant DECIMAL(10,2) DEFAULT 0,
            date_creation TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_frais (annee_scolaire, classe_nom)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS paiements_eleves (
            id INT AUTO_INCREMENT PRIMARY KEY,
            annee_scolaire VARCHAR(9) NOT NULL,
            eleve_id INT NOT NULL,
            classe_nom VARCHAR(100) NOT NULL,
            type_frais ENUM('scolarite','assurance','apeae','cantine','bus','fournitures') NOT NULL,
            montant DECIMAL(10,2) NOT NULL,
            date_paiement DATE NOT NULL,
            moyen_paiement_id INT DEFAULT NULL,
            reference VARCHAR(100) DEFAULT NULL,
            statut ENUM('valide','en_attente','annule') DEFAULT 'valide',
            utilisateur VARCHAR(100) DEFAULT NULL,
            date_creation TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_paiement_eleve FOREIGN KEY (eleve_id) REFERENCES eleves (id) ON DELETE CASCADE,
            CONSTRAINT fk_paiement_moyen FOREIGN KEY (moyen_paiement_id) REFERENCES moyens_paiement (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
} catch (Exception $e) {
    // on continue sans interrompre l'affichage
}

// Récupérations de base
$classes = [];
$moyens_paiement = [];
try {
    $classes = $pdo->query("SELECT * FROM classes ORDER BY niveau, nom_classe")->fetchAll(PDO::FETCH_ASSOC);
    $moyens_paiement = $pdo->query("SELECT * FROM moyens_paiement WHERE est_actif = 1 ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$message_succes = '';
$message_erreur = '';

// Traitements POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['action']) && $_POST['action'] === 'save_frais') {
            if (!empty($_POST['idx']) && is_array($_POST['idx'])) {
                $sql = "INSERT INTO frais_classes
                        (annee_scolaire, classe_nom, 
                         scolarite_active, scolarite_montant,
                         assurance_active, assurance_montant,
                         apeae_active, apeae_montant,
                         cantine_active, cantine_montant,
                         bus_active, bus_montant,
                         fournitures_active, fournitures_montant)
                        VALUES
                        (:annee, :classe,
                         :sc_a, :sc_m,
                         :ass_a, :ass_m,
                         :ap_a, :ap_m,
                         :ca_a, :ca_m,
                         :bus_a, :bus_m,
                         :four_a, :four_m)
                        ON DUPLICATE KEY UPDATE
                         scolarite_active = VALUES(scolarite_active),
                         scolarite_montant = VALUES(scolarite_montant),
                         assurance_active = VALUES(assurance_active),
                         assurance_montant = VALUES(assurance_montant),
                         apeae_active = VALUES(apeae_active),
                         apeae_montant = VALUES(apeae_montant),
                         cantine_active = VALUES(cantine_active),
                         cantine_montant = VALUES(cantine_montant),
                         bus_active = VALUES(bus_active),
                         bus_montant = VALUES(bus_montant),
                         fournitures_active = VALUES(fournitures_active),
                         fournitures_montant = VALUES(fournitures_montant)";
                $stmt = $pdo->prepare($sql);

                foreach ($_POST['idx'] as $i) {
                    $classe = $_POST['classe_nom_'.$i] ?? '';
                    if (!$classe) continue;

                    $types = ['scolarite','assurance','apeae','cantine','bus','fournitures'];
                    $vals = [];
                    foreach ($types as $t) {
                        $montant = isset($_POST[$t.'_montant_'.$i]) && is_numeric($_POST[$t.'_montant_'.$i])
                            ? (float)$_POST[$t.'_montant_'.$i] : 0;
                        // Active si un montant strictement positif est saisi, sinon inactif
                        $vals[$t.'_m'] = $montant;
                        $vals[$t.'_a'] = $montant > 0 ? 1 : 0;
                    }

                    $stmt->execute([
                        ':annee' => $annee_courante,
                        ':classe' => $classe,
                        ':sc_a' => $vals['scolarite_a'], ':sc_m' => $vals['scolarite_m'],
                        ':ass_a' => $vals['assurance_a'], ':ass_m' => $vals['assurance_m'],
                        ':ap_a' => $vals['apeae_a'], ':ap_m' => $vals['apeae_m'],
                        ':ca_a' => $vals['cantine_a'], ':ca_m' => $vals['cantine_m'],
                        ':bus_a' => $vals['bus_a'], ':bus_m' => $vals['bus_m'],
                        ':four_a' => $vals['fournitures_a'], ':four_m' => $vals['fournitures_m'],
                    ]);
                }
                $message_succes = "Configuration des frais enregistrée.";
            }
        } elseif (isset($_POST['action']) && $_POST['action'] === 'add_paiement') {
            $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

            $eleve_id = (int)($_POST['eleve_id'] ?? 0);
            $classe_nom = $_POST['classe_nom'] ?? '';
            $type_frais = $_POST['type_frais'] ?? 'scolarite';
            $montant = (float)($_POST['montant'] ?? 0);
            $date_paiement = $_POST['date_paiement'] ?? date('Y-m-d');
            $moyen_paiement_id = !empty($_POST['moyen_paiement_id']) ? (int)$_POST['moyen_paiement_id'] : null;
            $reference = trim($_POST['reference'] ?? '');

            if ($eleve_id <= 0 || !$classe_nom || $montant <= 0 || !in_array($type_frais, ['scolarite','assurance','apeae','cantine','bus','fournitures'], true)) {
                throw new Exception("Données de paiement invalides.");
            }

            // Élève
            $stmt = $pdo->prepare("SELECT prenom_eleve, nom_eleve, matricule_eleve FROM eleves WHERE id = ?");
            $stmt->execute([$eleve_id]);
            $eleve = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$eleve) {
                throw new Exception("Élève introuvable.");
            }
            $eleve_nom_complet = trim(($eleve['prenom_eleve'] ?? '') . ' ' . ($eleve['nom_eleve'] ?? ''));

            // Générer un numéro de reçu si absent
            if ($reference === '') {
                $year = date('Y', strtotime($date_paiement));
                $q = $pdo->query("SELECT MAX(CAST(SUBSTRING(reference, 8) AS UNSIGNED)) AS max_num FROM paiements_eleves WHERE reference LIKE 'R-{$year}-%'");
                $r = $q ? $q->fetch(PDO::FETCH_ASSOC) : null;
                $next = ($r && $r['max_num'] ? (int)$r['max_num'] : 0) + 1;
                $reference = 'R-' . $year . '-' . str_pad($next, 5, '0', STR_PAD_LEFT);
            }

            $pdo->beginTransaction();

            // Insert paiement
            $stmt = $pdo->prepare("
                INSERT INTO paiements_eleves
                (annee_scolaire, eleve_id, classe_nom, type_frais, montant, date_paiement, moyen_paiement_id, reference, statut, utilisateur)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'valide', ?)
            ");
            $stmt->execute([
                $annee_courante, $eleve_id, $classe_nom, $type_frais, $montant, $date_paiement, $moyen_paiement_id, $reference, $utilisateur_nom
            ]);
            $paiement_id = (int)$pdo->lastInsertId();

            // Écriture automatique en trésorerie
            // Compte défaut: Caisse (530000) puis Banque (512000) sinon 1er 5xxxx
            $compte_id = null;
            $res = $pdo->query("SELECT id FROM comptes_comptables WHERE numero_compte IN ('530000','512000') ORDER BY FIELD(numero_compte,'530000','512000') LIMIT 1");
            $r = $res ? $res->fetch(PDO::FETCH_ASSOC) : null;
            if ($r && !empty($r['id'])) {
                $compte_id = (int)$r['id'];
            } else {
                $res = $pdo->query("SELECT id FROM comptes_comptables WHERE numero_compte LIKE '5%' ORDER BY numero_compte LIMIT 1");
                $r = $res ? $res->fetch(PDO::FETCH_ASSOC) : null;
                $compte_id = $r ? (int)$r['id'] : null;
            }
            // Moyen de paiement défaut: Espèces
            if (empty($moyen_paiement_id)) {
                $stmtTmp = $pdo->prepare("SELECT id FROM moyens_paiement WHERE nom = ? LIMIT 1");
                $stmtTmp->execute(['Espèces']);
                $rowTmp = $stmtTmp->fetch(PDO::FETCH_ASSOC);
                if ($rowTmp) {
                    $moyen_paiement_id = (int)$rowTmp['id'];
                } else {
                    $res = $pdo->query("SELECT id FROM moyens_paiement ORDER BY id LIMIT 1");
                    $rowTmp = $res ? $res->fetch(PDO::FETCH_ASSOC) : null;
                    $moyen_paiement_id = $rowTmp ? (int)$rowTmp['id'] : null;
                }
            }

            if ($compte_id !== null) {
                $libelleTres = "Paiement " . $type_frais . " - " . $eleve_nom_complet;
                $stmt = $pdo->prepare("
                    INSERT INTO tresorerie
                    (compte_id, date_operation, libelle, montant, type_operation, moyen_paiement_id,
                     numero_piece, beneficiaire, categorie, utilisateur)
                    VALUES (?, ?, ?, ?, 'entree', ?, ?, ?, 'scolarite', ?)
                ");
                $stmt->execute([$compte_id, $date_paiement, $libelleTres, $montant, $moyen_paiement_id, $reference, $eleve_nom_complet, $utilisateur_nom]);
            }

            $pdo->commit();

            // Recalculs pour la ligne de l'élève
            // Frais classe
            $stmt = $pdo->prepare("SELECT * FROM frais_classes WHERE annee_scolaire = ? AND classe_nom = ? LIMIT 1");
            $stmt->execute([$annee_courante, $classe_nom]);
            $fsel_ajax = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $types_lib = ['scolarite','assurance','apeae','cantine','bus','fournitures'];
            $types_affiches_ajax = [];
            foreach ($types_lib as $k) {
                $mont = isset($fsel_ajax[$k . '_montant']) ? (float)$fsel_ajax[$k . '_montant'] : 0;
                $act = isset($fsel_ajax[$k . '_active']) ? (int)$fsel_ajax[$k . '_active'] : 0;
                if ($mont > 0 || $act) {
                    $types_affiches_ajax[] = $k;
                }
            }

            // Sommes payées par type
            $stmt = $pdo->prepare("
                SELECT type_frais, COALESCE(SUM(montant),0) AS total
                FROM paiements_eleves
                WHERE annee_scolaire = ? AND classe_nom = ? AND eleve_id = ? AND statut = 'valide'
                GROUP BY type_frais
            ");
            $stmt->execute([$annee_courante, $classe_nom, $eleve_id]);
            $paye_par_type = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $paye_par_type[$row['type_frais']] = (float)$row['total'];
            }

            $du_par_type = [];
            foreach ($types_affiches_ajax as $tk) {
                $du_par_type[$tk] = (!empty($fsel_ajax[$tk . '_active'])) ? (float)$fsel_ajax[$tk . '_montant'] : 0.0;
            }
            $tot_du = array_sum($du_par_type);
            $tot_paye = 0.0;
            foreach ($types_affiches_ajax as $tk) {
                $tot_paye += (float)($paye_par_type[$tk] ?? 0);
            }
            $reste = max(0, $tot_du - $tot_paye);

            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'success' => true,
                    'message' => 'Paiement enregistré.',
                    'paiement_id' => $paiement_id,
                    'reference' => $reference,
                    'montant' => $montant,
                    'type_frais' => $type_frais,
                    'date' => $date_paiement,
                    'eleve_nom' => $eleve_nom_complet,
                    'types_order' => $types_affiches_ajax,
                    'per_type' => [
                        'du' => $du_par_type,
                        'paye' => $paye_par_type
                    ],
                    'tot_du' => $tot_du,
                    'tot_paye' => $tot_paye,
                    'reste' => $reste
                ]);
                exit;
            } else {
                $message_succes = "Paiement enregistré. Reçu n° " . $reference;
            }
        }
    } catch (Exception $ex) {
        $message_erreur = "Erreur: " . $ex->getMessage();
    }
}

// Charger configuration des frais par classe (map)
$frais_par_classe = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM frais_classes WHERE annee_scolaire = ?");
    $stmt->execute([$annee_courante]);
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $frais_par_classe[$r['classe_nom']] = $r;
    }
} catch (Exception $e) {}

// Endpoint AJAX: liste des élèves et tableau HTML pour une classe
if (isset($_GET['action']) && $_GET['action'] === 'list_eleves') {
    header('Content-Type: application/json; charset=utf-8');
    $classe_req = $_GET['classe'] ?? '';
    try {
        if ($classe_req === '') {
            echo json_encode(['success' => false, 'message' => 'Classe manquante.']); exit;
        }

        // Configuration des frais de la classe
        $fsel = $frais_par_classe[$classe_req] ?? null;
        $types_lib_all = ['scolarite'=>'Scolarité','assurance'=>'Assurance','apeae'=>'APEAE','cantine'=>'Cantine','bus'=>'Bus','fournitures'=>'Fournitures'];
        $types_affiches_loc = [];
        if ($fsel) {
            foreach ($types_lib_all as $k => $lib) {
                $montant = isset($fsel[$k . '_montant']) ? (float)$fsel[$k . '_montant'] : 0;
                $actif = isset($fsel[$k . '_active']) ? (int)$fsel[$k . '_active'] : 0;
                if ($montant > 0 || $actif) {
                    $types_affiches_loc[$k] = $lib;
                }
            }
        }

        // Élèves de la classe
        $stmt = $pdo->prepare("SELECT * FROM eleves WHERE classe_eleve = ? ORDER BY nom_eleve, prenom_eleve");
        $stmt->execute([$classe_req]);
        $eleves_loc = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Paiements agrégés par élève/type
        $stmt = $pdo->prepare("
            SELECT eleve_id, type_frais, SUM(montant) AS total
            FROM paiements_eleves
            WHERE classe_nom = ? AND annee_scolaire = ? AND statut = 'valide'
            GROUP BY eleve_id, type_frais
        ");
        $stmt->execute([$classe_req, $annee_courante]);
        $paiements_map_loc = [];
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $eid = (int)$r['eleve_id'];
            $t = $r['type_frais'];
            if (!isset($paiements_map_loc[$eid])) $paiements_map_loc[$eid] = [];
            $paiements_map_loc[$eid][$t] = (float)$r['total'];
        }

        // Totaux classe
        $du_unit = 0;
        if ($fsel) {
            $du_unit += ($fsel['scolarite_active'] ? (float)$fsel['scolarite_montant'] : 0);
            $du_unit += ($fsel['assurance_active'] ? (float)$fsel['assurance_montant'] : 0);
            $du_unit += ($fsel['apeae_active'] ? (float)$fsel['apeae_montant'] : 0);
            $du_unit += ($fsel['cantine_active'] ? (float)$fsel['cantine_montant'] : 0);
            $du_unit += ($fsel['bus_active'] ? (float)$fsel['bus_montant'] : 0);
            $du_unit += ($fsel['fournitures_active'] ? (float)$fsel['fournitures_montant'] : 0);
        }
        $nb_e = count($eleves_loc);
        $du_classe = $du_unit * $nb_e;
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(montant),0) FROM paiements_eleves WHERE classe_nom = ? AND annee_scolaire = ? AND statut = 'valide'");
        $stmt->execute([$classe_req, $annee_courante]);
        $paye_classe = (float)$stmt->fetchColumn();
        $taux_classe = $du_classe > 0 ? round(($paye_classe/$du_classe)*100,1) : 0;

        // Construire le tableau HTML (même structure que celui du rendu serveur)
        ob_start();
        ?>
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-2 text-left text-xs font-semibold text-gray-600">Élève</th>
                    <th class="px-4 py-2 text-left text-xs font-semibold text-gray-600">Matricule</th>
                    <?php foreach (($types_affiches_loc ?? []) as $k => $lib): ?>
                        <th class="px-4 py-2 text-center text-xs font-semibold text-gray-600"><?= $lib ?></th>
                    <?php endforeach; ?>
                    <th class="px-4 py-2 text-center text-xs font-semibold text-gray-600">Total payé</th>
                    <th class="px-4 py-2 text-center text-xs font-semibold text-gray-600">Total dû</th>
                    <th class="px-4 py-2 text-center text-xs font-semibold text-gray-600">Reste</th>
                    <th class="px-4 py-2 text-center text-xs font-semibold text-gray-600">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-100">
                <?php foreach ($eleves_loc as $el): 
                    $eid = (int)$el['id'];
                    $p = $paiements_map_loc[$eid] ?? [];
                    $du_par_type = [];
                    if (!empty($types_affiches_loc)) {
                        foreach (array_keys($types_affiches_loc) as $tk2) {
                            $act_key = $tk2 . '_active';
                            $mont_key = $tk2 . '_montant';
                            $du_par_type[$tk2] = ($fsel && !empty($fsel[$act_key])) ? (float)$fsel[$mont_key] : 0;
                        }
                    }
                    $tot_du = array_sum($du_par_type);
                    $tot_paye = 0.0;
                    if (!empty($types_affiches_loc)) {
                        foreach (array_keys($types_affiches_loc) as $tk2) {
                            $tot_paye += (float)($p[$tk2] ?? 0);
                        }
                    }
                    $reste = max(0, $tot_du - $tot_paye);
                    
                    // Récupérer le dernier paiement pour le bouton "Reçue"
                    $stmt_dernier_paiement = $pdo->prepare("
                        SELECT reference, date_paiement, montant, type_frais 
                        FROM paiements_eleves 
                        WHERE eleve_id = ? AND classe_nom = ? AND annee_scolaire = ? AND statut = 'valide' 
                        ORDER BY date_paiement DESC, id DESC 
                        LIMIT 1
                    ");
                    $stmt_dernier_paiement->execute([$eid, $classe_req, $annee_courante]);
                    $dernier_paiement = $stmt_dernier_paiement->fetch(PDO::FETCH_ASSOC);
                ?>
                <tr class="hover:bg-gray-50" 
                    data-eleve-id="<?= $eid ?>"
                    data-eleve-nom="<?= htmlspecialchars($el['prenom_eleve'].' '.$el['nom_eleve']) ?>"
                    <?php foreach ($du_par_type as $tk => $dv): 
                        $pv = isset($p[$tk]) ? (float)$p[$tk] : 0; 
                        $rv = max(0, (float)$dv - $pv);
                    ?>
                    data-<?= $tk ?>-du="<?= $dv ?>"
                    data-<?= $tk ?>-paye="<?= $pv ?>"
                    data-<?= $tk ?>-reste="<?= $rv ?>"
                    <?php endforeach; ?>
                    data-tot-du="<?= $tot_du ?>"
                    data-tot-paye="<?= $tot_paye ?>">
                
                    <td class="py-2">
                        <div class="text-sm font-medium text-gray-900"><?= htmlspecialchars($el['prenom_eleve'].' '.$el['nom_eleve']) ?></div>
                        <div class="text-xs text-gray-500"><?= htmlspecialchars($el['classe_eleve']) ?></div>
                    </td>
                    <td class="py-2 text-sm text-gray-700"><?= htmlspecialchars($el['matricule_eleve']) ?></td>
                    <?php foreach (array_keys($types_affiches_loc ?? []) as $tk):
                        $duv = $du_par_type[$tk] ?? 0;
                        $pyv = isset($p[$tk]) ? (float)$p[$tk] : 0;
                    ?>
                    <td class="py-2 text-center text-sm">
                        <span class="inline-block px-2 py-0.5 rounded bg-gray-100 text-gray-700"><?= formatFG($pyv,false) ?>/<?= formatFG($duv,false) ?></span>
                    </td>
                    <?php endforeach; ?>
                    <td class="py-2 text-center text-sm font-semibold text-emerald-700"><?= formatFG($tot_paye) ?></td>
                    <td class="py-2 text-center text-sm font-semibold"><?= formatFG($tot_du) ?></td>
                    <td class="py-2 text-center text-sm font-semibold <?= $reste>0?'text-red-600':'text-emerald-700' ?>"><?= formatFG($reste) ?></td>
                    <td class="py-2 text-center space-x-1">
                        <button class="px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white rounded text-sm"
                                onclick="ouvrirPaiementModal(this.closest('tr'))">
                            Mettre à jour
                        </button>
                        <?php if ($dernier_paiement): ?>
                        <button class="px-3 py-1.5 bg-green-600 hover:bg-green-700 text-white rounded text-sm"
                                onclick="imprimerDernierRecu(<?= $eid ?>, '<?= htmlspecialchars($classe_req) ?>', '<?= htmlspecialchars($annee_courante) ?>')">
                            Reçue
                        </button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php
                    $pourcentage = $tot_du > 0 ? min(100, round(($tot_paye / $tot_du) * 100)) : 0;
                    $colspan = 6 + (isset($types_affiches_loc) ? count($types_affiches_loc) : 0);
                ?>
                <tr class="hover:bg-transparent">
                    <td colspan="<?= $colspan ?>" class="px-4 pt-0 pb-4">
                        <div class="mt-1">
                            <div class="w-full bg-gray-200 rounded-full h-2">
                                <div class="h-2 rounded-full <?= ($pourcentage >= 100 ? 'bg-emerald-600' : ($pourcentage >= 50 ? 'bg-emerald-500' : 'bg-yellow-500')) ?>" style="width: <?= $pourcentage ?>%"></div>
                            </div>
                            <div class="mt-1 text-xs text-gray-500 text-right"><?= $pourcentage ?>%</div>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($eleves_loc)): ?>
                    <tr><td colspan="10" class="px-4 py-8 text-center text-gray-500">Aucun élève dans cette classe</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
        $table_html = ob_get_clean();

        echo json_encode([
            'success' => true,
            'table_html' => $table_html,
            'types' => array_keys($types_affiches_loc),
            'du_classe' => $du_classe,
            'paye_classe' => $paye_classe,
            'taux_classe' => $taux_classe
        ]);
        exit;
    } catch (Exception $e) {}
}

// Endpoint AJAX: historique des paiements d'un élève + totaux
if (isset($_GET['action']) && $_GET['action'] === 'historique_eleve') {
    header('Content-Type: application/json; charset=utf-8');
    $eleve_id = isset($_GET['eleve_id']) ? (int)$_GET['eleve_id'] : 0;
    $classe = $_GET['classe'] ?? '';
    $annee = $_GET['annee'] ?? $annee_courante;
    try {
        if ($eleve_id <= 0 || $classe === '') {
            echo json_encode(['success' => false, 'message' => 'Paramètres manquants.']); exit;
        }
        // Paiements détaillés (date, type, montant)
        $stmt = $pdo->prepare("
            SELECT date_paiement AS date, type_frais, montant
            FROM paiements_eleves
            WHERE eleve_id = ? AND classe_nom = ? AND annee_scolaire = ? AND statut = 'valide'
            ORDER BY date_paiement ASC, id ASC
        ");
        $stmt->execute([$eleve_id, $classe, $annee]);
        $historique = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Total payé
        $stmt2 = $pdo->prepare("
            SELECT COALESCE(SUM(montant),0) AS total_paye
            FROM paiements_eleves
            WHERE eleve_id = ? AND classe_nom = ? AND annee_scolaire = ? AND statut = 'valide'
        ");
        $stmt2->execute([$eleve_id, $classe, $annee]);
        $total_paye = (float)$stmt2->fetchColumn();

        // Total dû (unitaire de la classe)
        $fsel = $frais_par_classe[$classe] ?? null;
        $total_du = 0.0;
        if ($fsel) {
            $total_du += ($fsel['scolarite_active'] ? (float)$fsel['scolarite_montant'] : 0);
            $total_du += ($fsel['assurance_active'] ? (float)$fsel['assurance_montant'] : 0);
            $total_du += ($fsel['apeae_active'] ? (float)$fsel['apeae_montant'] : 0);
            $total_du += ($fsel['cantine_active'] ? (float)$fsel['cantine_montant'] : 0);
            $total_du += ($fsel['bus_active'] ? (float)$fsel['bus_montant'] : 0);
            $total_du += ($fsel['fournitures_active'] ? (float)$fsel['fournitures_montant'] : 0);
        }
        $total_restant = max(0, $total_du - $total_paye);

        echo json_encode([
            'success' => true,
            'historique' => $historique,
            'total_du' => $total_du,
            'total_paye' => $total_paye,
            'total_restant' => $total_restant
        ]);
        exit;
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]); exit;
    }
}

// Endpoint AJAX: dernier paiement d'un élève
if (isset($_GET['action']) && $_GET['action'] === 'dernier_paiement') {
    header('Content-Type: application/json; charset=utf-8');
    $eleve_id = isset($_GET['eleve_id']) ? (int)$_GET['eleve_id'] : 0;
    $classe = $_GET['classe'] ?? '';
    $annee = $_GET['annee'] ?? $annee_courante;
    try {
        if ($eleve_id <= 0 || $classe === '') {
            echo json_encode(['success' => false, 'message' => 'Paramètres manquants.']); exit;
        }
        
        // Récupérer le dernier paiement
        $stmt = $pdo->prepare("
            SELECT p.*, e.prenom_eleve, e.nom_eleve, e.matricule_eleve 
            FROM paiements_eleves p
            JOIN eleves e ON p.eleve_id = e.id
            WHERE p.eleve_id = ? AND p.classe_nom = ? AND p.annee_scolaire = ? AND p.statut = 'valide' 
            ORDER BY p.date_paiement DESC, p.id DESC 
            LIMIT 1
        ");
        $stmt->execute([$eleve_id, $classe, $annee]);
        $dernier_paiement = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$dernier_paiement) {
            echo json_encode(['success' => false, 'message' => 'Aucun paiement trouvé pour cet élève.']); exit;
        }

        echo json_encode([
            'success' => true,
            'paiement' => $dernier_paiement
        ]);
        exit;
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]); exit;
    }
}

// Classe sélectionnée (pour l'onglet Paiements)
$classe_sel = $_GET['classe'] ?? '';
$eleves_classe = [];
$paiements_map = []; // [eleve_id][type] = total payé

if ($classe_sel) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM eleves WHERE classe_eleve = ? ORDER BY nom_eleve, prenom_eleve");
        $stmt->execute([$classe_sel]);
        $eleves_classe = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare("
            SELECT eleve_id, type_frais, SUM(montant) as total
            FROM paiements_eleves
            WHERE classe_nom = ? AND annee_scolaire = ? AND statut = 'valide'
            GROUP BY eleve_id, type_frais
        ");
        $stmt->execute([$classe_sel, $annee_courante]);
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $eid = (int)$r['eleve_id'];
            $t = $r['type_frais'];
            if (!isset($paiements_map[$eid])) $paiements_map[$eid] = [];
            $paiements_map[$eid][$t] = (float)$r['total'];
        }
    } catch (Exception $e) {}
}

// Statistiques par classe et globales
$stats_par_classe = [];
$global_du = 0; $global_paye = 0; $global_eleves = 0;
try {
    foreach ($classes as $c) {
        $nom = $c['nom_classe'];
        $nb = (int)$pdo->prepare("SELECT COUNT(*) FROM eleves WHERE classe_eleve = ?")
                       ->execute([$nom]) ? (int)$pdo->query("SELECT COUNT(*) FROM eleves WHERE classe_eleve = ".$pdo->quote($nom))->fetchColumn() : 0;

        $f = $frais_par_classe[$nom] ?? null;
        $du_unitaire = 0;
        if ($f) {
            $du_unitaire += ($f['scolarite_active'] ? (float)$f['scolarite_montant'] : 0);
            $du_unitaire += ($f['assurance_active'] ? (float)$f['assurance_montant'] : 0);
            $du_unitaire += ($f['apeae_active'] ? (float)$f['apeae_montant'] : 0);
            $du_unitaire += ($f['cantine_active'] ? (float)$f['cantine_montant'] : 0);
            $du_unitaire += ($f['bus_active'] ? (float)$f['bus_montant'] : 0);
            $du_unitaire += ($f['fournitures_active'] ? (float)$f['fournitures_montant'] : 0);
        }
        $du_total = $nb * $du_unitaire;

        $stmtP = $pdo->prepare("SELECT COALESCE(SUM(montant),0) FROM paiements_eleves WHERE classe_nom = ? AND annee_scolaire = ? AND statut = 'valide'");
        $stmtP->execute([$nom, $annee_courante]);
        $paye = (float)$stmtP->fetchColumn();

        $taux = $du_total > 0 ? round(($paye / $du_total) * 100, 1) : 0;

        $stats_par_classe[] = [
            'classe' => $nom,
            'eleves' => $nb,
            'du' => $du_total,
            'paye' => $paye,
            'taux' => $taux
        ];

        $global_du += $du_total;
        $global_paye += $paye;
        $global_eleves += $nb;
    }
} catch (Exception $e) {}

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Gestion de la Scolarité</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script> -->

    <!-- Tailwind CSS -->
    <script src="tailwind.js"></script>
    <!-- Font Awesome & Google Fonts -->
    <link href="all.min.css" rel="stylesheet" />
    <script src="all.min.js"></script>

    <script src="chart.js"></script>
    
    <style>
        .multi-only{display:none;}
        .modal-multi .single-only{display:none;}
        .modal-multi .multi-only{display:block;}
    </style>
</head>
<body class="bg-gray-50">
    <div class="bg-white border-b px-6 py-4">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">
                    <i class="fas fa-money-bill-wave mr-3 text-emerald-600"></i>
                    Gestion de la Scolarité
                </h1>
                <p class="text-gray-600">Année scolaire: <span class="font-semibold"><?= htmlspecialchars($annee_courante) ?></span></p>
            </div>
            <div class="flex items-center space-x-3">
                <?php if ($message_succes): ?>
                    <div class="px-3 py-1 rounded bg-green-100 text-green-700 text-sm"><?= htmlspecialchars($message_succes) ?></div>
                <?php endif; ?>
                <?php if ($message_erreur): ?>
                    <div class="px-3 py-1 rounded bg-red-100 text-red-700 text-sm"><?= htmlspecialchars($message_erreur) ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="max-w-[1400px] mx-auto p-6">
        <!-- Onglets -->
        <div class="bg-white rounded-lg shadow">
            <div class="border-b px-4">
                <nav class="-mb-px flex space-x-6">
                    <button id="tab-paiements" onclick="switchTab('paiements')" class="py-3 text-gray-500 hover:text-gray-700">
                        <i class="fa-solid fa-receipt mr-2"></i>Paiements élèves
                    </button>
                    <button id="tab-stats" onclick="switchTab('stats')" class="py-3 text-gray-500 hover:text-gray-700">
                        <i class="fa-solid fa-chart-line mr-2"></i>Statistiques
                    </button>
                    <button id="tab-config" onclick="switchTab('config')" class="py-3 text-gray-500 hover:text-gray-700">
                        <i class="fa-solid fa-sliders mr-2"></i>Configuration des frais
                    </button>
                </nav>
            </div>

            <!-- Contenu: Configuration -->
            <div id="content-config" class="p-4">
                <div class="flex justify-between items-center mb-3">
                    <div class="text-sm text-gray-600">Modifier les montants de frais par classe</div>
                    <button type="button" id="btn-edit-config" class="px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white rounded text-sm">
                        <i class="fa-solid fa-pen-to-square mr-2"></i>Modifier
                    </button>
                </div>
                <form method="POST" id="form-config-frais">
                    <input type="hidden" name="action" value="save_frais">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-2 text-left text-xs font-semibold text-gray-600">Classe</th>
                                    <th class="px-4 py-2 text-center text-xs font-semibold text-gray-600">Scolarité</th>
                                    <th class="px-4 py-2 text-center text-xs font-semibold text-gray-600">Assurance</th>
                                    <th class="px-4 py-2 text-center text-xs font-semibold text-gray-600">APEAE</th>
                                    <th class="px-4 py-2 text-center text-xs font-semibold text-gray-600">Cantine</th>
                                    <th class="px-4 py-2 text-center text-xs font-semibold text-gray-600">Bus</th>
                                    <th class="px-4 py-2 text-center text-xs font-semibold text-gray-600">Fournitures</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-100">
                                <?php $i=0; foreach ($classes as $cl): $i++; 
                                    $nom = $cl['nom_classe'];
                                    $f = $frais_par_classe[$nom] ?? null;
                                ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-2 whitespace-nowrap">
                                        <input type="hidden" name="idx[]" value="<?= $i ?>">
                                        <input type="hidden" name="classe_nom_<?= $i ?>" value="<?= htmlspecialchars($nom) ?>">
                                        <div class="text-sm font-medium text-gray-900"><?= htmlspecialchars($nom) ?></div>
                                        <div class="text-xs text-gray-500"><?= htmlspecialchars($cl['niveau']) ?></div>
                                    </td>
                                    <?php
                                      $def = [
                                        'scolarite' => ['a' => $f['scolarite_active'] ?? 0, 'm' => $f['scolarite_montant'] ?? 0],
                                        'assurance' => ['a' => $f['assurance_active'] ?? 0, 'm' => $f['assurance_montant'] ?? 0],
                                        'apeae' => ['a' => $f['apeae_active'] ?? 0, 'm' => $f['apeae_montant'] ?? 0],
                                        'cantine' => ['a' => $f['cantine_active'] ?? 0, 'm' => $f['cantine_montant'] ?? 0],
                                        'bus' => ['a' => $f['bus_active'] ?? 0, 'm' => $f['bus_montant'] ?? 0],
                                        'fournitures' => ['a' => $f['fournitures_active'] ?? 0, 'm' => $f['fournitures_montant'] ?? 0],
                                      ];
                                      foreach (['scolarite','assurance','apeae','cantine','bus','fournitures'] as $t):
                                    ?>
                                    <td class="px-4 py-2">
                                        <div class="flex items-center justify-center space-x-2">
                                            <input type="number" step="0.01" min="0" name="<?= $t ?>_montant_<?= $i ?>" value="<?= htmlspecialchars($def[$t]['m']) ?>" class="w-28 px-2 py-1 border rounded text-sm" placeholder="Montant" data-config-input="1" disabled>
                                            <span class="text-xs text-gray-500">FG</span>
                                        </div>
                                    </td>
                                    <?php endforeach; ?>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="p-4 flex justify-end">
                        <button type="submit" id="btn-save-config" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded" disabled>
                            <i class="fa-solid fa-floppy-disk mr-2"></i>Enregistrer
                        </button>
                    </div>
                </form>
            </div>
                

            <!-- Contenu: Paiements -->
            <div id="content-paiements" class="p-4 hidden">
                <div class="flex flex-wrap items-center gap-3 md:gap-4 mb-4">
                    <label class="text-sm text-gray-700 shrink-0">Classe:</label>
                    <select onchange="changerClasse(this.value)" class="px-3 py-2 border rounded w-full sm:w-56 md:w-64">

                        <?php foreach ($classes as $cl): ?>
                            <option value="<?= htmlspecialchars($cl['nom_classe']) ?>" <?= $classe_sel === $cl['nom_classe'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cl['nom_classe'].' - '.$cl['niveau']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php
                        $fsel = $frais_par_classe[$classe_sel] ?? null;
                        $du_unit = 0;
                        if ($fsel) {
                            $du_unit += ($fsel['scolarite_active'] ? (float)$fsel['scolarite_montant'] : 0);
                            $du_unit += ($fsel['assurance_active'] ? (float)$fsel['assurance_montant'] : 0);
                            $du_unit += ($fsel['apeae_active'] ? (float)$fsel['apeae_montant'] : 0);
                            $du_unit += ($fsel['cantine_active'] ? (float)$fsel['cantine_montant'] : 0);
                            $du_unit += ($fsel['bus_active'] ? (float)$fsel['bus_montant'] : 0);
                            $du_unit += ($fsel['fournitures_active'] ? (float)$fsel['fournitures_montant'] : 0);
                        }
                        $nb_e = count($eleves_classe);
                        $du_classe = $du_unit * $nb_e;
                        $paye_classe = 0.0;
                        try {
                            $st = $pdo->prepare("SELECT COALESCE(SUM(montant),0) FROM paiements_eleves WHERE classe_nom = ? AND annee_scolaire = ? AND statut = 'valide'");
                            $st->execute([$classe_sel, $annee_courante]);
                            $paye_classe = (float)$st->fetchColumn();
                        } catch(Exception $e){}
                        $taux_classe = $du_classe > 0 ? round(($paye_classe/$du_classe)*100,1) : 0;
                    ?>
                    <div class="md:ml-auto w-full md:w-auto grid grid-cols-3 gap-2 text-sm mt-3 md:mt-0">
                        <div class="px-3 py-2 bg-gray-50 border rounded">
                            <div class="text-gray-500">Dû classe</div>
                            <div class="font-semibold" id="classe_du_val"><?= formatFG($du_classe) ?></div>
                        </div>
                        <div class="px-3 py-2 bg-gray-50 border rounded">
                            <div class="text-gray-500">Payé classe</div>
                            <div class="font-semibold text-emerald-700" id="classe_paye_val"><?= formatFG($paye_classe) ?></div>
                        </div>
                        <div class="px-3 py-2 bg-gray-50 border rounded">
                            <div class="text-gray-500">Taux</div>
                            <div class="font-semibold" id="classe_taux_val"><?= $taux_classe ?>%</div>
                        </div>
                    </div>
                    <div class="w-full md:w-auto md:ml-4 flex flex-wrap items-center gap-2 mt-3 md:mt-0">
                        <input id="searchEleve" type="text" placeholder="Recherche nom/matricule..." class="px-3 py-2 border rounded text-sm">
                        <select id="etatFilter" class="px-3 py-2 border rounded text-sm w-full sm:w-40">
                            <option value="">Tous</option>
                            <option value="paye">Payé</option>
                            <option value="partiel">Partiel</option>
                            <option value="impaye">Impayé</option>
                        </select>
                        <?php
                            $export_url = 'scolarite.php?export=csv&classe=' . urlencode($classe_sel);
                            $etat_get = $_GET['etat'] ?? '';
                            if ($etat_get) { $export_url .= '&etat=' . urlencode($etat_get); }
                        ?>
                        <a id="exportCsvBtn" href="<?= htmlspecialchars($export_url) ?>" class="px-3 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded text-sm">
                            Exporter CSV
                        </a>
                    </div>
                </div>

                <?php
                    $types_lib = ['scolarite'=>'Scolarité','assurance'=>'Assurance','apeae'=>'APEAE','cantine'=>'Cantine','bus'=>'Bus','fournitures'=>'Fournitures'];
                    $types_affiches = [];
                    if (isset($fsel)) {
                        foreach ($types_lib as $k => $lib) {
                            $montant = isset($fsel[$k . '_montant']) ? (float)$fsel[$k . '_montant'] : 0;
                            $actif = isset($fsel[$k . '_active']) ? (int)$fsel[$k . '_active'] : 0;
                            if ($montant > 0 || $actif) {
                                $types_affiches[$k] = $lib;
                            }
                        }
                    }
                ?>
               <script>window.typesAffiches = <?= json_encode(array_keys($types_affiches ?? [])) ?>;</script>
                <div id="tableContainer" class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-2 text-left text-xs font-semibold text-gray-600">Élève</th>
                                <th class="px-4 py-2 text-left text-xs font-semibold text-gray-600">Matricule</th>
                                <?php foreach (($types_affiches ?? []) as $k => $lib): ?>
                                    <th class="px-4 py-2 text-center text-xs font-semibold text-gray-600"><?= $lib ?></th>
                                <?php endforeach; ?>
                                <th class="px-4 py-2 text-center text-xs font-semibold text-gray-600">Total payé</th>
                                <th class="px-4 py-2 text-center text-xs font-semibold text-gray-600">Total dû</th>
                                <th class="px-4 py-2 text-center text-xs font-semibold text-gray-600">Reste</th>
                                <th class="px-4 py-2 text-center text-xs font-semibold text-gray-600">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-100">
                            <?php foreach ($eleves_classe as $el):
                                $eid = (int)$el['id'];
                                $p = $paiements_map[$eid] ?? [];
                                $du_par_type = [];
                                if (!empty($types_affiches)) {
                                    foreach (array_keys($types_affiches) as $tk2) {
                                        $act_key = $tk2 . '_active';
                                        $mont_key = $tk2 . '_montant';
                                        $du_par_type[$tk2] = ($fsel && !empty($fsel[$act_key])) ? (float)$fsel[$mont_key] : 0;
                                    }
                                }
                                $tot_du = array_sum($du_par_type);
                                $tot_paye = 0.0;
                                if (!empty($types_affiches)) {
                                    foreach (array_keys($types_affiches) as $tk2) {
                                        $tot_paye += (float)($p[$tk2] ?? 0);
                                    }
                                }
                                $reste = max(0, $tot_du - $tot_paye);
                                
                                // Récupérer le dernier paiement pour le bouton "Reçue"
                                $stmt_dernier_paiement = $pdo->prepare("
                                    SELECT reference, date_paiement, montant, type_frais 
                                    FROM paiements_eleves 
                                    WHERE eleve_id = ? AND classe_nom = ? AND annee_scolaire = ? AND statut = 'valide' 
                                    ORDER BY date_paiement DESC, id DESC 
                                    LIMIT 1
                                ");
                                $stmt_dernier_paiement->execute([$eid, $classe_sel, $annee_courante]);
                                $dernier_paiement = $stmt_dernier_paiement->fetch(PDO::FETCH_ASSOC);
                            ?>
                            <tr class="hover:bg-gray-50" 
                                data-eleve-id="<?= $eid ?>"
                                data-eleve-nom="<?= htmlspecialchars($el['prenom_eleve'].' '.$el['nom_eleve']) ?>"
                                <?php foreach ($du_par_type as $tk => $dv): 
                                    $pv = isset($p[$tk]) ? (float)$p[$tk] : 0; 
                                    $rv = max(0, (float)$dv - $pv);
                                ?>
                                data-<?= $tk ?>-du="<?= $dv ?>"
                                data-<?= $tk ?>-paye="<?= $pv ?>"
                                data-<?= $tk ?>-reste="<?= $rv ?>"
                                <?php endforeach; ?>
                                data-tot-du="<?= $tot_du ?>"
                                data-tot-paye="<?= $tot_paye ?>"
                            >
                                <td class="px-4 py-2">
                                    <div class="text-sm font-medium text-gray-900"><?= htmlspecialchars($el['prenom_eleve'].' '.$el['nom_eleve']) ?></div>
                                    <div class="text-xs text-gray-500"><?= htmlspecialchars($el['classe_eleve']) ?></div>
                                </td>
                                <td class="px-4 py-2 text-sm text-gray-700"><?= htmlspecialchars($el['matricule_eleve']) ?></td>
                                <?php foreach (array_keys($types_affiches ?? []) as $tk):
                                    $duv = $du_par_type[$tk] ?? 0;
                                    $pyv = isset($p[$tk]) ? (float)$p[$tk] : 0;
                                ?>
                                <td class="px-4 py-2 text-center text-sm">
                                    <span class="inline-block px-2 py-0.5 rounded bg-gray-100 text-gray-700"><?= formatFG($pyv,false) ?>/<?= formatFG($duv,false) ?></span>
                                </td>
                                <?php endforeach; ?>
                                <td class="px-4 py-2 text-center text-sm font-semibold text-emerald-700"><?= formatFG($tot_paye) ?></td>
                                <td class="px-4 py-2 text-center text-sm font-semibold"><?= formatFG($tot_du) ?></td>
                                <td class="px-4 py-2 text-center text-sm font-semibold <?= $reste>0?'text-red-600':'text-emerald-700' ?>"><?= formatFG($reste) ?></td>
                                <td class="px-4 py-2 text-center space-x-1">
                                    <button class="px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white rounded text-sm"
                                            onclick="ouvrirPaiementModal(this.closest('tr'))">
                                        Mettre à jour
                                    </button>
                                    <?php if ($dernier_paiement): ?>
                                    <button class="px-3 py-1.5 bg-green-600 hover:bg-green-700 text-white rounded text-sm"
                                            onclick="imprimerDernierRecu(<?= $eid ?>, '<?= htmlspecialchars($classe_sel) ?>', '<?= htmlspecialchars($annee_courante) ?>')">
                                        Reçue
                                    </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php
                                // Barre de progression globale (paiements / dû)
                                $pourcentage = $tot_du > 0 ? min(100, round(($tot_paye / $tot_du) * 100)) : 0;
                                $colspan = 6 + (isset($types_affiches) ? count($types_affiches) : 0);
                            ?>
                            <tr class="hover:bg-transparent">
                                <td colspan="<?= $colspan ?>" class="px-4 pt-0 pb-4">
                                    <div class="mt-1">
                                        <div class="w-full bg-gray-200 rounded-full h-2">
                                            <div class="h-2 rounded-full <?= ($pourcentage >= 100 ? 'bg-emerald-600' : ($pourcentage >= 50 ? 'bg-emerald-500' : 'bg-yellow-500')) ?>" style="width: <?= $pourcentage ?>%"></div>
                                        </div>
                                        <div class="mt-1 text-xs text-gray-500 text-right"><?= $pourcentage ?>%</div>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($eleves_classe)): ?>
                                <tr><td colspan="10" class="px-4 py-8 text-center text-gray-500">Aucun élève dans cette classe</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Contenu: Statistiques -->
            <div id="content-stats" class="p-4 hidden">
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-4">
                    <div class="bg-white border rounded p-4">
                        <div class="text-sm text-gray-500">Total élèves</div>
                        <div class="text-2xl font-semibold"><?= number_format($global_eleves) ?></div>
                    </div>
                    <div class="bg-white border rounded p-4">
                        <div class="text-sm text-gray-500">Total dû</div>
                        <div class="text-2xl font-semibold"><?= formatFG($global_du) ?></div>
                    </div>
                    <div class="bg-white border rounded p-4">
                        <div class="text-sm text-gray-500">Total payé</div>
                        <div class="text-2xl font-semibold text-emerald-700"><?= formatFG($global_paye) ?></div>
                    </div>
                    <div class="bg-white border rounded p-4">
                        <div class="text-sm text-gray-500">Taux global</div>
                        <div class="text-2xl font-semibold">
                            <?= $global_du > 0 ? round(($global_paye/$global_du)*100,1) : 0 ?>%
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded border overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-2 text-left text-xs font-semibold text-gray-600">Classe</th>
                                <th class="px-4 py-2 text-center text-xs font-semibold text-gray-600">Élèves</th>
                                <th class="px-4 py-2 text-center text-xs font-semibold text-gray-600">Dû</th>
                                <th class="px-4 py-2 text-center text-xs font-semibold text-gray-600">Payé</th>
                                <th class="px-4 py-2 text-center text-xs font-semibold text-gray-600">Taux</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-100">
                            <?php foreach ($stats_par_classe as $st): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-2 text-sm font-medium text-gray-900"><?= htmlspecialchars($st['classe']) ?></td>
                                <td class="px-4 py-2 text-center text-sm"><?= $st['eleves'] ?></td>
                                <td class="px-4 py-2 text-center text-sm"><?= formatFG($st['du']) ?></td>
                                <td class="px-4 py-2 text-center text-sm text-emerald-700"><?= formatFG($st['paye']) ?></td>
                                <td class="px-4 py-2 text-center text-sm font-semibold"><?= $st['taux'] ?>%</td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($stats_par_classe)): ?>
                                <tr><td colspan="5" class="px-4 py-8 text-center text-gray-500">Aucune classe</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>

    <!-- Modal Paiement -->
    <div id="paiementModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="bg-white rounded-lg w-full max-w-lg">
                <div class="flex items-center justify-between px-5 py-3 border-b">
                    <h3 class="font-semibold text-gray-800">
                        <i class="fa-solid fa-credit-card mr-2 text-blue-600"></i>
                        Enregistrer un paiement
                    </h3>
                    <button onclick="fermerPaiementModal()" class="text-gray-400 hover:text-gray-600">
                        <i class="fa-solid fa-xmark text-xl"></i>
                    </button>
                </div>
                <form method="POST" class="p-5" id="formPaiement">
                    <input type="hidden" name="action" value="add_paiement">
                    <input type="hidden" name="eleve_id" id="pay_eleve_id">
                    <input type="hidden" name="classe_nom" value="<?= htmlspecialchars($classe_sel) ?>">
                    <input type="hidden" name="multi_mode" id="multi_mode" value="0">

                    <div class="mb-3">
                        <div class="text-sm text-gray-500">Élève</div>
                        <div id="pay_eleve_nom" class="font-semibold text-gray-900"></div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div class="single-only">
                            <label class="block text-sm text-gray-700 mb-1">Type de frais</label>
                            <select name="type_frais" id="pay_type" class="w-full border rounded px-3 py-2" onchange="majMontantReste()">
                                <?php if (!empty($types_affiches)): ?>
                                    <?php foreach ($types_affiches as $k=>$lib): ?>
                                        <option value="<?= $k ?>"><?= $lib ?></option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm text-gray-700 mb-1">Date</label>
                            <input type="date" name="date_paiement" value="<?= date('Y-m-d') ?>" class="w-full border rounded px-3 py-2">
                        </div>
                    </div>

                    <div class="grid grid-cols-3 gap-4 mt-3 single-only">
                        <div>
                            <div class="text-xs text-gray-500">Dû</div>
                            <div id="montant_du" class="font-semibold">0</div>
                        </div>
                        <div>
                            <div class="text-xs text-gray-500">Déjà payé</div>
                            <div id="montant_paye" class="font-semibold text-emerald-700">0</div>
                        </div>
                        <div>
                            <div class="text-xs text-gray-500">Reste</div>
                            <div id="montant_reste" class="font-semibold text-red-600">0</div>
                        </div>
                    </div>

                    <div id="multiSection" class="multi-only mt-4 border rounded p-3">
                        <div class="text-sm font-semibold mb-2">Montants par poste</div>
                        <?php if (!empty($types_affiches)): foreach ($types_affiches as $k=>$lib): ?>
                        <div class="flex items-center justify-between py-1">
                            <div>
                                <div class="text-sm font-medium"><?= $lib ?></div>
                                <div class="text-xs text-gray-500">
                                    Dû: <span id="mdu-<?= $k ?>">0</span> | Payé: <span id="mpaye-<?= $k ?>">0</span> | Reste: <span id="mreste-<?= $k ?>">0</span>
                                </div>
                            </div>
                            <div class="flex items-center gap-2">
                                <input type="number" step="0.01" min="0" name="montants[<?= $k ?>]" id="minput-<?= $k ?>" class="w-28 px-2 py-1 border rounded text-sm" placeholder="Montant">
                                <span class="text-xs text-gray-500">FG</span>
                            </div>
                        </div>
                        <?php endforeach; endif; ?>
                    </div>

                    <div class="grid grid-cols-2 gap-4 mt-3">
                        <div class="single-only">
                            <label class="block text-sm text-gray-700 mb-1">Montant à encaisser (FG)</label>
                            <input type="number" step="0.01" min="0" name="montant" id="pay_montant" class="w-full border rounded px-3 py-2">
                        </div>
                        <div>
                            <label class="block text-sm text-gray-700 mb-1">Moyen de paiement</label>
                            <select name="moyen_paiement_id" class="w-full border rounded px-3 py-2">
                                <option value="">-- Sélectionner --</option>
                                <?php foreach ($moyens_paiement as $m): ?>
                                    <option value="<?= (int)$m['id'] ?>"><?= htmlspecialchars($m['nom']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="flex justify-end gap-2 mt-5">
                        <button type="button" onclick="fermerPaiementModal()" class="px-4 py-2 bg-gray-100 text-gray-700 rounded">Annuler</button>
                        <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded">
                            <i class="fa-solid fa-save mr-2"></i>Enregistrer
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

<script>
const TYPE_LABELS = { scolarite:'Scolarité', assurance:'Assurance', apeae:'APEAE', cantine:'Cantine', bus:'Bus', fournitures:'Fournitures' };

// Fallback minimal pour Swal si non chargé
if (typeof window.Swal === 'undefined') {
    window.Swal = {
        fire: function(opts) {
            const title = (opts && opts.title) ? opts.title : '';
            const msg = (opts && (opts.text || '')) ? (opts.text || '') : '';
            alert([title, msg].filter(Boolean).join('\n'));
            return Promise.resolve({});
        },
        showLoading: function() { /* noop */ },
        close: function() { /* noop */ }
    };
    // certaines implémentations utilisent "swal" en minuscule
    window.swal = window.Swal;
}
    };
}

// Nouvelle fonction pour imprimer le dernier reçu
function imprimerDernierRecu(eleveId, classe, annee) {
    // Afficher un indicateur de chargement
    Swal.fire({
        title: 'Chargement...',
        text: 'Récupération du dernier reçu',
        allowOutsideClick: false,
        didOpen: () => {
            Swal.showLoading();
        }
    });

    // Récupérer les données du dernier paiement
    fetch(`scolarite.php?action=dernier_paiement&eleve_id=${eleveId}&classe=${encodeURIComponent(classe)}&annee=${encodeURIComponent(annee)}`)
        .then(r => r.json())
        .then(data => {
            Swal.close();
            if (!data || data.success !== true) {
                throw new Error((data && data.message) ? data.message : 'Erreur de chargement du reçu.');
            }

            const paiement = data.paiement;
            
            // Préparer les données pour l'impression
            const infoRecu = {
                reference: paiement.reference,
                date: paiement.date_paiement,
                eleve: (paiement.prenom_eleve || '') + ' ' + (paiement.nom_eleve || ''),
                type: TYPE_LABELS[paiement.type_frais] || paiement.type_frais,
                montant: paiement.montant,
                items: [{
                    type: TYPE_LABELS[paiement.type_frais] || paiement.type_frais,
                    montant: paiement.montant
                }],
                classe: classe,
                eleve_id: eleveId,
                annee: annee
            };

            // Appeler la fonction d'impression existante
            imprimerRecu(infoRecu);
        })
        .catch(err => {
            Swal.fire({
                icon: 'error',
                title: 'Erreur',
                text: err.message || 'Impossible de récupérer le dernier reçu.'
            });
        });
}

function switchTab(tab) {
    const tabs = ['config','paiements','stats'];
    tabs.forEach(t => {
        const contentEl = document.getElementById('content-' + t);
        const tabEl = document.getElementById('tab-' + t);
        if (contentEl) contentEl.classList.add('hidden');
        if (tabEl) {
            tabEl.classList.remove('border-emerald-600','text-emerald-700','font-medium');
            tabEl.classList.add('text-gray-500');
        }
    });
    const showContent = document.getElementById('content-' + tab);
    const showTab = document.getElementById('tab-' + tab);
    if (showContent) showContent.classList.remove('hidden');
    if (showTab) {
        showTab.classList.add('border-emerald-600','text-emerald-700','font-medium');
        showTab.classList.remove('text-gray-500');
    }
}
function changerClasse(cl) {
    const container = document.getElementById('tableContainer');
    const duEl = document.getElementById('classe_du_val');
    const payeEl = document.getElementById('classe_paye_val');
    const tauxEl = document.getElementById('classe_taux_val');
    const exportBtn = document.getElementById('exportCsvBtn');
    const hiddenClasse = document.querySelector('#formPaiement input[name="classe_nom"]');
    if (container) {
        container.innerHTML = '<div class="p-6 text-center text-gray-500">Chargement…</div>';
    }
    fetch('scolarite.php?action=list_eleves&classe=' + encodeURIComponent(cl))
        .then(r => r.json())
        .then(data => {
            if (!data || data.success !== true) throw new Error((data && data.message) ? data.message : 'Erreur de chargement.');
            if (container) {
                container.innerHTML = data.table_html || '<div class="p-6 text-center text-gray-500">Aucune donnée</div>';
            }
            if (typeof window !== 'undefined') {
                window.typesAffiches = Array.isArray(data.types) ? data.types : [];
            }
            if (duEl) duEl.textContent = formatFG(data.du_classe || 0);
            if (payeEl) payeEl.textContent = formatFG(data.paye_classe || 0);
            if (tauxEl) tauxEl.textContent = (data.taux_classe || 0) + '%';
            if (exportBtn) {
                const url = new URL(exportBtn.href, window.location.origin);
                url.searchParams.set('classe', cl);
                const e = new URL(window.location.href).searchParams.get('etat') || '';
                const q = document.getElementById('searchEleve') ? document.getElementById('searchEleve').value.trim() : '';
                if (e) url.searchParams.set('etat', e); else url.searchParams.delete('etat');
                if (q) url.searchParams.set('q', q); else url.searchParams.delete('q');
                exportBtn.href = url.toString();
            }
            if (hiddenClasse) hiddenClasse.value = cl;
        })
        .catch(err => {
            if (container) {
                container.innerHTML = '<div class="p-6 text-center text-red-600">' + (err.message || 'Erreur') + '</div>';
            }
        });
}
function ouvrirPaiementModal(tr) {
    const id = tr.dataset.eleveId;
    const nom = tr.dataset.eleveNom;
    document.getElementById('pay_eleve_id').value = id;
    document.getElementById('pay_eleve_nom').textContent = nom;
    // Mémoriser les montants par type dans dataset du modal
    const modal = document.getElementById('paiementModal');
    ['scolarite','assurance','apeae','cantine','bus','fournitures'].forEach(t => {
        modal.dataset[t+'Du'] = tr.dataset[t+'Du'] || '0';
        modal.dataset[t+'Paye'] = tr.dataset[t+'Paye'] || '0';
        modal.dataset[t+'Reste'] = tr.dataset[t+'Reste'] || '0';
    });
    const mt = document.getElementById('multiToggle');
    if (mt) mt.checked = false;
    toggleMultiMode(false);
    // Peupler dynamiquement la liste des types selon la classe affichée
    const sel = document.getElementById('pay_type');
    if (sel) {
        const types = (Array.isArray(window.typesAffiches) && window.typesAffiches.length) ? window.typesAffiches : Object.keys(TYPE_LABELS);
        sel.innerHTML = '';
        types.forEach(t => {
            const opt = document.createElement('option');
            opt.value = t;
            opt.textContent = TYPE_LABELS[t] || t;
            sel.appendChild(opt);
        });
        sel.value = types.includes('scolarite') ? 'scolarite' : (types[0] || '');
    }
    majMontantReste();
    modal.classList.remove('hidden');
}
function fermerPaiementModal() {
    document.getElementById('paiementModal').classList.add('hidden');
}
function majMontantReste() {
    const modal = document.getElementById('paiementModal');
    const type = document.getElementById('pay_type').value;
    const du = parseFloat(modal.dataset[type+'Du'] || '0');
    const paye = parseFloat(modal.dataset[type+'Paye'] || '0');
    const reste = Math.max(0, du - paye);
    document.getElementById('montant_du').textContent = formatFG(du, false);
    document.getElementById('montant_paye').textContent = formatFG(paye, false);
    document.getElementById('montant_reste').textContent = formatFG(reste, false);
    document.getElementById('pay_montant').value = reste > 0 ? reste : '';
    const pm = document.getElementById('pay_montant');
    if (pm) {
        pm.max = reste;
        if (parseFloat(pm.value || '0') > reste) {
            pm.value = reste > 0 ? reste : '';
        }
    }
}
function toggleMultiMode(checked) {
    const modal = document.getElementById('paiementModal');
    const hidden = document.getElementById('multi_mode');
    if (hidden) hidden.value = checked ? '1' : '0';
    if (modal) {
        if (checked) { modal.classList.add('modal-multi'); populateMultiSection(); }
        else { modal.classList.remove('modal-multi'); }
    }
}
function populateMultiSection() {
    const modal = document.getElementById('paiementModal');
    let types = (Array.isArray(window.typesAffiches) && window.typesAffiches.length) ? window.typesAffiches : Object.keys(TYPE_LABELS);
    types.forEach((t) => {
        const du = parseFloat(modal.dataset[t+'Du'] || '0');
        const paye = parseFloat(modal.dataset[t+'Paye'] || '0');
        const reste = Math.max(0, du - paye);
        const duEl = document.getElementById('mdu-' + t);
        const pyEl = document.getElementById('mpaye-' + t);
        const rsEl = document.getElementById('mreste-' + t);
        if (duEl) duEl.textContent = formatFG(du, false);
        if (pyEl) pyEl.textContent = formatFG(paye, false);
        if (rsEl) rsEl.textContent = formatFG(reste, false);
        const inEl = document.getElementById('minput-' + t);
        if (inEl) {
            inEl.max = reste;
            if (parseFloat(inEl.value || '0') > reste) inEl.value = reste > 0 ? reste : '';
        }
    });
}
function formatFG(n, withCurrency=true) {
    if (!n || isNaN(n)) { return withCurrency ? '0 FG' : '0'; }
    const s = Math.round(parseFloat(n)).toLocaleString('fr-FR');
    return withCurrency ? s + ' FG' : s;
}

function imprimerRecu(info) {
    const w = window.open('', '_blank', 'width=900,height=1200');
    if (!w) return;

    // Données école depuis $ecole_info
    const schoolName = "<?= htmlspecialchars($ecole_info["nom_ecole"] ?? "Votre Établissement") ?>";
    const schoolAbbrev = "<?= htmlspecialchars($ecole_info["nom_abrege"] ?? "") ?>";
    const schoolAddress = "<?= htmlspecialchars($ecole_info["adresse_ecole"] ?? "Adresse de l\'établissement") ?>";
    const schoolCity = "<?= htmlspecialchars($ecole_info["ville_ecole"] ?? "") ?>";
    const schoolPhone = "<?= htmlspecialchars($ecole_info["tel_ecole"] ?? "Téléphone") ?>";
    const schoolEmail = "<?= htmlspecialchars($ecole_info["mail_ecole"] ?? "Email") ?>";
    const schoolWebsite = "<?= htmlspecialchars($ecole_info["site_web"] ?? "") ?>";
    const schoolFooterNote = "<?= htmlspecialchars($ecole_info["pied_de_page_ecole"] ?? "") ?>";
    const logoUrl = "<?= htmlspecialchars($ecole_info["logo_ecole"] ?? "") ?>";

    const css = `
        <style>
            * { 
                box-sizing: border-box; 
                margin: 0; 
                padding: 0; 
            }
            body {
                font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
                background: #f8fafc;
                color: #1e293b;
                line-height: 1.5;
                padding: 30px;
                font-size: 14px;
            }
            .receipt-container {
                max-width: 800px;
                margin: 0 auto;
                background: white;
                border-radius: 16px;
                box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
                overflow: hidden;
            }
            .header-banner {
                background: linear-gradient(120deg, #1e3a8a 0%, #1e40af 100%);
                color: white;
                padding: 24px 32px;
                text-align: center;
                position: relative;
            }
            .logo-container {
                width: 100px;
                height: 100px;
                margin: 0 auto 16px;
                background: white;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                border: 3px solid rgba(255,255,255,0.3);
                box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            }
            .logo-container img {
                width: 70px;
                height: 70px;
                object-fit: contain;
            }
            .school-name {
                font-size: 24px;
                font-weight: 800;
                letter-spacing: -0.5px;
                margin-bottom: 4px;
            }
            .school-meta {
                font-size: 13px;
                opacity: 0.9;
                max-width: 600px;
                margin: 0 auto;
                line-height: 1.4;
            }
            .receipt-header {
                text-align: center;
                padding: 24px 32px 16px;
            }
            .receipt-title {
                font-size: 22px;
                font-weight: 700;
                color: #0f172a;
                margin-bottom: 4px;
            }
            .receipt-subtitle {
                font-size: 13px;
                color: #64748b;
            }
            .receipt-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
                gap: 16px;
                padding: 0 32px 24px;
            }
            .info-card {
                background: #f8fafc;
                border: 1px solid #e2e8f0;
                border-radius: 12px;
                padding: 14px;
                display: ruby;
                text-wrap-mode: nowrap;
            }
            .info-label {
                font-size: 12px;
                color: #64748b;
                margin-bottom: 4px;
                font-weight: 600;
            }
            .info-value {
                font-size: 15px;
                font-weight: 600;
                color: #0f172a;
            }
            .divider {
                height: 1px;
                background: #e2e8f0;
                margin: 0 32px;
            }
            .items-section {
                padding: 0px 32px;
            }
            .items-table {
                width: 100%;
                border-collapse: collapse;
                margin-bottom: 20px;
            }
            .items-table th {
                text-align: left;
                padding: 10px 12px;
                font-weight: 600;
                color: #475569;
                font-size: 13px;
                border-bottom: 2px solid #cbd5e1;
            }
            .items-table td {
                padding: 12px 12px;
                font-size: 14px;
                border-bottom: 1px solid #e2e8f0;
            }
            .items-table .amount {
                text-align: right;
                font-weight: 600;
                color: #0f172a;
            }
            .summary-section {
                padding: 0 32px 24px;
            }
            .summary-row {
                display: flex;
                justify-content: space-between;
                padding: 10px 0;
                font-size: 15px;
            }
            .summary-label {
                color: #475569;
            }
            .summary-total {
                font-weight: 800;
                color: #1e3a8a;
                font-size: 17px;
            }
            .signature-section {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 32px;
                padding: 0 32px 24px;
            }
            .signature-box {
                border-top: 1px dashed #94a3b8;
                padding-top: 30px;
                margin-top: 8px;
            }
            .signature-label {
                font-size: 13px;
                color: #64748b;
                text-align: center;
                margin-top: 8px;
            }
            .footer-note {
                text-align: center;
                padding: 20px 32px 28px;
                font-size: 12px;
                color: #64748b;
                background: #f8fafc;
                border-top: 1px solid #e2e8f0;
            }
            .print-button {
                display: block;
                width: 200px;
                margin: 24px auto;
                padding: 12px;
                background: #1e3a8a;
                color: white;
                border: none;
                border-radius: 8px;
                font-weight: 600;
                cursor: pointer;
                font-size: 15px;
            }
            @media print {
                body { 
                    background: white; 
                    padding: 0; 
                }
                .receipt-container { 
                    box-shadow: none; 
                    border-radius: 0; 
                }
                .print-button { 
                    display: none; 
                }
                * {
                    -webkit-print-color-adjust: exact !important;
                    print-color-adjust: exact !important;
                }
            }
        </style>
    `;

    const logoHtml = logoUrl
        ? `<div class="logo-container"><img src="${logoUrl}" alt="Logo"></div>`
        : `<div class="logo-container" style="font-size:10px;color:#94a3b8;display:flex;align-items:center;justify-content:center;">LOGO</div>`;

    const metaLine = [schoolAbbrev, schoolCity, schoolAddress].filter(Boolean).join(' • ');
    const contactsLine = [schoolPhone, schoolEmail, schoolWebsite].filter(Boolean).join(' • ');

    const itemsTableHtml = (Array.isArray(info.items) && info.items.length)
        ? `
            <table class="items-table">
                <thead>
                    <tr>
                        <th>Description</th>
                        <th class="amount">Montant</th>
                    </tr>
                </thead>
                <tbody>
                    ${info.items.map(it => `
                        <tr>
                            <td>${(it.type_label || it.type || '').toString()}</td>
                            <td class="amount">${formatFG(it.montant || 0)}</td>
                        </tr>
                    `).join('')}
                </tbody>
            </table>
        `
        : `
            <table class="items-table">
                <thead>
                    <tr>
                        <th>Description</th>
                        <th class="amount">Montant</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>${info.type || ''}</td>
                        <td class="amount">${formatFG(info.montant || 0)}</td>
                    </tr>
                </tbody>
            </table>
        `;

    const html = `
        <html>
        <head>
            <meta charset="utf-8">
            <title>Reçu ${info.reference || ''}</title>
            ${css}
        </head>
        <body>
            <div class="receipt-container">
                <div class="header-banner">
                    ${logoHtml}
                    <div class="school-name">${schoolName}</div>
                    <div class="school-meta">
                        ${metaLine}<br>
                        ${contactsLine}
                    </div>
                </div>

                <div class="receipt-header">
                    <div class="receipt-title">Reçu de paiement</div>
                    <div class="receipt-subtitle">N° ${info.reference || ''}</div>
                </div>

                <div class="receipt-grid">
                    <div class="info-card">
                        <div class="info-label">Date</div>
                        <div class="info-value">${info.date || ''}</div>
                    </div>
                    <div class="info-card">
                        <div class="info-label">Classe</div>
                        <div class="info-value">${info.classe || ''}</div>
                    </div>
                    <div class="info-card">
                        <div class="info-label">Élève</div>
                        <div class="info-value">${info.eleve || ''}</div>
                    </div>
                    <div class="info-card">
                        <div class="info-label">Type de frais</div>
                        <div class="info-value">${info.type || ''}</div>
                    </div>
                    <div class="info-card">
                        <div class="info-label">Montant encaissé</div>
                        <div class="info-value">${formatFG(info.montant || 0)}</div>
                    </div>
                    <div class="info-card">
                        <div class="info-label">Enregistré par</div>
                        <div class="info-value"><?= htmlspecialchars($utilisateur_nom) ?></div>
                    </div>
                </div>

                <div class="divider"></div>

                <div class="items-section">
                    ${itemsTableHtml}
                </div>

                <div class="summary-section">
                    <div class="summary-row" id="totaux-global">
                        <div class="summary-label">Totaux élève (année)</div>
                        <div class="summary-value">Chargement…</div>
                    </div>
                </div>

                <div class="divider"></div>

                <div style="padding: 0 32px 16px;">
                    <div style="font-weight: 700; margin-bottom: 12px;">Historique des paiements</div>
                    <table id="historique-table" style="width:100%; border-collapse: collapse;">
                        <thead>
                            <tr>
                                <th style="background-color:#f3f4f6; border-bottom:1px solid #e5e7eb; padding:8px; text-align:left;">Date</th>
                                <th style="background-color:#f3f4f6; border-bottom:1px solid #e5e7eb; padding:8px; text-align:left;">Type</th>
                                <th style="background-color:#f3f4f6; border-bottom:1px solid #e5e7eb; padding:8px; text-align:right;">Montant</th>
                            </tr>
                        </thead>
                        <tbody id="historique-body">
                            <tr><td colspan="3" style="text-align:center; color:#6b7280; padding:8px;">Chargement…</td></tr>
                        </tbody>
                    </table>
                </div>

                <div class="signature-section">
                    <div class="signature-box">
                        <div class="signature-label">Signature du comptable / caissier</div>
                    </div>
                    <div class="signature-box">
                        <div class="signature-label">Signature du parent / élève</div>
                    </div>
                </div>

                <div class="footer-note">
                    ${schoolFooterNote || 'Ce reçu est à conserver. Merci pour votre paiement.'}
                </div>

                <button class="print-button" onclick="window.print()">Imprimer le reçu</button>
            </div>

            <script>
                (function(){
                    try {
                        const params = new URLSearchParams({
                            action: 'historique_eleve',
                            eleve_id: String(${JSON.stringify(info.eleve_id || '')}),
                            classe: ${JSON.stringify(info.classe || '')},
                            annee: ${JSON.stringify(info.annee || '')}
                        });
                        fetch('scolarite.php?' + params.toString(), { credentials: 'same-origin' })
                            .then(r => r.json())
                            .then(j => {
                                const tbody = document.getElementById('historique-body');
                                const totaux = document.getElementById('totaux-global');
                                if (!j || !j.success) {
                                    if (tbody) tbody.innerHTML = '<tr><td colspan="3" style="text-align:center; color:#ef4444; padding:8px;">Historique indisponible</td></tr>';
                                    if (totaux) totaux.querySelector('.summary-value').textContent = 'Indisponible';
                                    return;
                                }
                                const fmt = function(v){ return (v||0).toLocaleString('fr-FR',{minimumFractionDigits:2,maximumFractionDigits:2}) + ' FG'; };
                                const rows = (j.historique || []).map(it => {
                                    const d = it.date ? new Date(it.date) : null;
                                    const dt = d && !isNaN(d.getTime()) ? d.toLocaleDateString('fr-FR') : (it.date || '');
                                    const type = it.type_frais || '';
                                    const mt = (typeof it.montant !== 'undefined') ? Number(it.montant) : 0;
                                    return '<tr>' +
                                        '<td style="border-bottom:1px solid #e5e7eb; padding:8px;">' + dt + '</td>' +
                                        '<td style="border-bottom:1px solid #e5e7eb; padding:8px;">' + type + '</td>' +
                                        '<td style="border-bottom:1px solid #e5e7eb; padding:8px; text-align:right;">' + fmt(mt) + '</td>' +
                                    '</tr>';
                                }).join('');
                                if (tbody) {
                                    tbody.innerHTML = rows || '<tr><td colspan="3" style="text-align:center; color:#6b7280; padding:8px;">Aucun paiement enregistré</td></tr>';
                                }
                                if (totaux) {
                                    const du = Number(j.total_du || 0);
                                    const paye = Number(j.total_paye || 0);
                                    const reste = Number(j.total_restant || 0);
                                    totaux.querySelector('.summary-value').textContent = 'Dû: ' + fmt(du) + ' • Payé: ' + fmt(paye) + ' • Reste: ' + fmt(reste);
                                }
                            })
                            .catch(() => {
                                const tbody = document.getElementById('historique-body');
                                const totaux = document.getElementById('totaux-global');
                                if (tbody) tbody.innerHTML = '<tr><td colspan="3" style="text-align:center; color:#ef4444; padding:8px;">Erreur de chargement</td></tr>';
                                if (totaux) totaux.querySelector('.summary-value').textContent = 'Erreur';
                            });
                    } catch(e) {
                        console.warn('Historique paiements non chargé:', e);
                    }
                })();
            <\/script>
        </body>
        </html>
    `;

    w.document.open();
    w.document.write(html);
    w.document.close();
}

// Activer l'onglet par défaut selon présence de classe dans l'URL + initialisations (AJAX, filtres, export)
document.addEventListener('DOMContentLoaded', () => {
    const url = new URL(window.location.href);
    const hasClasse = url.searchParams.get('classe');
    switchTab('paiements')

    // Activer/désactiver la modification des frais
    const editBtn = document.getElementById('btn-edit-config');
    const saveBtn = document.getElementById('btn-save-config');
    function setConfigEditable(edit) {
        document.querySelectorAll('input[data-config-input="1"]').forEach(inp => {
            inp.disabled = !edit;
            inp.classList.toggle('bg-gray-100', !edit);
        });
        if (saveBtn) saveBtn.disabled = !edit;
        if (editBtn) editBtn.textContent = edit ? 'Terminer modification' : 'Modifier';
    }
    setConfigEditable(false);
    if (editBtn) {
        editBtn.addEventListener('click', function() {
            const nowEnabled = saveBtn ? saveBtn.disabled : true;
            setConfigEditable(nowEnabled); // inverse l'état
        });
    }

    // 1) AJAX Form Paiement
    const form = document.getElementById('formPaiement');
    if (form) {
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const fd = new FormData(form);
            const submitBtn = form.querySelector('button[type="submit"]');
            if (submitBtn) { submitBtn.disabled = true; submitBtn.classList.add('opacity-50'); }
            // Validation multi-postes côté client
            const isMulti = (document.getElementById('multi_mode') && document.getElementById('multi_mode').value === '1');
            if (isMulti) {
                const types = (window.typesAffiches || []);
                let sum = 0;
                for (let i = 0; i < types.length; i++) {
                    const inp = document.getElementById('minput-' + types[i]);
                    if (inp) sum += parseFloat(inp.value || '0');
                }
                if (!sum || sum <= 0) {
                    if (submitBtn) { submitBtn.disabled = false; submitBtn.classList.remove('opacity-50'); }
                    Swal.fire({ icon: 'warning', title: 'Montant requis', text: 'Renseignez au moins un montant sur un poste.' });
                    return;
                }
            }
            try {
                const resp = await fetch(window.location.href, {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: fd
                });
                const data = await resp.json();
                if (!data || data.success !== true) {
                    throw new Error((data && data.message) ? data.message : 'Erreur inconnue.');
                }

                // MAJ ligne élève
                const eleveId = form.querySelector('[name="eleve_id"]').value;
                const tr = document.querySelector('tr[data-eleve-id="' + eleveId + '"]');
                if (tr) {
                    const tds = tr.querySelectorAll('td');
                    const typesOrder = data.types_order || (window.typesAffiches || []);
                    const perTypeDu = (data.per_type && data.per_type.du) ? data.per_type.du : {};
                    const perTypePaye = (data.per_type && data.per_type.paye) ? data.per_type.paye : {};
                    for (let i = 0; i < typesOrder.length; i++) {
                        const type = typesOrder[i];
                        const du = perTypeDu[type] ? Math.round(perTypeDu[type]) : 0;
                        const py = perTypePaye[type] ? Math.round(perTypePaye[type]) : 0;
                        const colIndex = 2 + i;
                        const cell = tds[colIndex];
                        if (cell) {
                            const span = cell.querySelector('span');
                            if (span) span.textContent = formatFG(py, false) + '/' + formatFG(du, false);
                        }
                        tr.dataset[type + 'Du'] = du;
                        tr.dataset[type + 'Paye'] = py;
                        tr.dataset[type + 'Reste'] = Math.max(0, du - py);
                    }
                    const typesCount = typesOrder.length;
                    const totPayeCell = tds[2 + typesCount];
                    const totDuCell = tds[3 + typesCount];
                    const totResteCell = tds[4 + typesCount];
                    if (totPayeCell) totPayeCell.textContent = formatFG(data.tot_paye);
                    if (totDuCell) totDuCell.textContent = formatFG(data.tot_du);
                    if (totResteCell) {
                        totResteCell.textContent = formatFG(data.reste);
                        if (data.reste > 0) {
                            totResteCell.classList.remove('text-emerald-700');
                            totResteCell.classList.add('text-red-600');
                        } else {
                            totResteCell.classList.remove('text-red-600');
                            totResteCell.classList.add('text-emerald-700');
                        }
                    }
                    // Progress bar (ligne suivante)
                    const progressTr = tr.nextElementSibling;
                    if (progressTr) {
                        const pourcentage = (data.tot_du > 0) ? Math.min(100, Math.round((data.tot_paye / data.tot_du) * 100)) : 0;
                        const bar = progressTr.querySelector('div > div > div');
                        const pctText = progressTr.querySelector('.mt-1.text-xs.text-gray-500.text-right');
                        if (bar) {
                            bar.style.width = pourcentage + '%';
                            bar.classList.remove('bg-emerald-600', 'bg-emerald-500', 'bg-yellow-500');
                            bar.classList.add(pourcentage >= 100 ? 'bg-emerald-600' : (pourcentage >= 50 ? 'bg-emerald-500' : 'bg-yellow-500'));
                        }
                        if (pctText) pctText.textContent = pourcentage + '%';
                        tr.dataset.totDu = data.tot_du;
                        tr.dataset.totPaye = data.tot_paye;
                    }

                    // MAJ tuiles de classe (increment simple)
                    try {
                        const payeClasseEl = document.getElementById('classe_paye_val');
                        const tauxClasseEl = document.getElementById('classe_taux_val');
                        const duClasseEl = document.getElementById('classe_du_val');
                        if (payeClasseEl && tauxClasseEl && duClasseEl) {
                            const currentPaye = parseInt((payeClasseEl.textContent || '0').replace(/[^0-9]/g, ''), 10) || 0;
                            const currentDu = parseInt((duClasseEl.textContent || '0').replace(/[^0-9]/g, ''), 10) || 0;
                            const newPaye = currentPaye + Math.round(data.montant || 0);
                            payeClasseEl.textContent = formatFG(newPaye);
                            const taux = currentDu > 0 ? Math.round((newPaye / currentDu) * 100) : 0;
                            tauxClasseEl.textContent = taux + '%';
                        }
                    } catch (e) {}
                }

                // Fermer modale
                fermerPaiementModal();
                if (submitBtn) { submitBtn.disabled = false; submitBtn.classList.remove('opacity-50'); }

                // SweetAlert + reçu
                const typeText = (() => {
                    const sel = document.getElementById('pay_type');
                    if (!sel) return data.type_frais;
                    const opt = sel.querySelector('option[value="' + data.type_frais + '"]');
                    return opt ? opt.textContent : data.type_frais;
                })();
                const receiptHtml = (function(){
                    if (Array.isArray(data.items) && data.items.length) {
                        let rows = data.items.map(it => '<div class="line"><b>' + (it.type_label || it.type) + ':</b> ' + formatFG(it.montant || 0) + '</div>').join('');
                        return '<div style="text-align:left">' +
                               '<div><b>Élève:</b> ' + (data.eleve_nom || '') + '</div>' +
                               '<div><b>Référence:</b> ' + (data.reference || '') + '</div>' +
                               '<div><b>Date:</b> ' + (data.date || '') + '</div>' +
                               rows +
                               '<div class="line"><b>Total:</b> ' + formatFG(data.montant || 0) + '</div>' +
                               '</div>';
                    } else {
                        return '<div style="text-align:left">' +
                               '<div><b>Élève:</b> ' + (data.eleve_nom || '') + '</div>' +
                               '<div><b>Type:</b> ' + typeText + '</div>' +
                               '<div><b>Montant:</b> ' + formatFG(data.montant) + '</div>' +
                               '<div><b>Référence:</b> ' + (data.reference || '') + '</div>' +
                               '<div><b>Date:</b> ' + (data.date || '') + '</div>' +
                               '</div>';
                    }
                })();
                // Automatically open/print receipt
                imprimerRecu({
                    reference: data.reference,
                    date: data.date,
                    eleve: data.eleve_nom,
                    type: typeText,
                    montant: data.montant,
                    items: data.items || [],
                    classe: (document.querySelector('#formPaiement input[name="classe_nom"]')?.value || ''),
                    eleve_id: eleveId,
                    annee: '<?= htmlspecialchars($annee_courante) ?>'
                });

                // Optional: still show a small success toast
                Swal.fire({
                    icon: 'success',
                    title: 'Paiement enregistré',
                    text: 'Le reçu a été ouvert pour impression.',
                    timer: 2000,
                    showConfirmButton: false
                });

            } catch (err) {
                if (submitBtn) { submitBtn.disabled = false; submitBtn.classList.remove('opacity-50'); }
                Swal.fire({ icon: 'error', title: 'Erreur', text: err.message || 'Une erreur est survenue.' });
            }
        });
    }

    // 2) Filtres DOM + export CSV
    const search = document.getElementById('searchEleve');
    const etat = document.getElementById('etatFilter');
    const exportBtn = document.getElementById('exportCsvBtn');
    const tbody = document.querySelector('#content-paiements tbody');
    function applyFilters() {
        const q = (search && search.value || '').trim().toLowerCase();
        const e = (etat && etat.value) || '';
        if (!tbody) return;
        const rows = Array.from(tbody.querySelectorAll('tr')).filter(tr => tr.dataset.eleveId);

        let payeClasse = 0;
        let duClasse = 0;

        rows.forEach(tr => {
            const next = tr.nextElementSibling;
            const nom = (tr.dataset.eleveNom || '').toLowerCase();
            const matCell = tr.querySelectorAll('td')[1];
            const mat = matCell ? (matCell.textContent || '').toLowerCase() : '';
            const totDu = parseFloat(tr.dataset.totDu || '0');
            const totPaye = parseFloat(tr.dataset.totPaye || '0');

            let visible = true;
            if (q) visible = (nom.indexOf(q) !== -1) || (mat.indexOf(q) !== -1);
            if (visible && e) {
                if (e === 'paye') visible = (totDu > 0 && totPaye >= totDu);
                else if (e === 'impaye') visible = (totDu > 0 && totPaye <= 0);
                else if (e === 'partiel') visible = (totDu > 0 && totPaye > 0 && totPaye < totDu);
            }
            tr.style.display = visible ? '' : 'none';
            if (next) next.style.display = visible ? '' : 'none';

            if (visible) {
                payeClasse += isNaN(totPaye) ? 0 : totPaye;
                duClasse += isNaN(totDu) ? 0 : totDu;
            }
        });

        const payeEl = document.getElementById('classe_paye_val');
        const duEl = document.getElementById('classe_du_val');
        const tauxEl = document.getElementById('classe_taux_val');
        if (payeEl && duEl && tauxEl) {
            payeEl.textContent = formatFG(payeClasse);
            duEl.textContent = formatFG(duClasse);
            const taux = duClasse > 0 ? Math.round((payeClasse / duClasse) * 100) : 0;
            tauxEl.textContent = taux + '%';
        }

        if (exportBtn) {
            const expUrl = new URL(exportBtn.href, window.location.origin);
            if (q) expUrl.searchParams.set('q', q); else expUrl.searchParams.delete('q');
            if (e) expUrl.searchParams.set('etat', e); else expUrl.searchParams.delete('etat');
            exportBtn.href = expUrl.toString();
        }
    }
    if (search) search.addEventListener('input', applyFilters);
    if (etat) etat.addEventListener('change', () => {
        const url2 = new URL(window.location.href);
        if (etat.value) url2.searchParams.set('etat', etat.value); else url2.searchParams.delete('etat');
        window.history.replaceState({}, '', url2.toString());
        applyFilters();
    });

    // Init depuis URL
    const q0 = url.searchParams.get('q') || '';
    const e0 = url.searchParams.get('etat') || '';
    if (search && q0) search.value = q0;
    if (etat && e0) etat.value = e0;
    applyFilters();

    // Charger le tableau au chargement selon la classe sélectionnée
    try {
        const selClasse = document.querySelector('#content-paiements select[onchange*="changerClasse"]');
        if (selClasse && selClasse.value) {
            changerClasse(selClasse.value);
        }
    } catch (e) {}
});
</script>
</body>
</html>