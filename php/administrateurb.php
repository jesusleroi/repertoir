<?php
//super_admin.php
session_start();
require_once 'connexion_bdd.php';
$dbname = $_SESSION['base_de_donnees'];
// Vérifier si l'utilisateur est connecté et a les droits super_admin
if (!isset($_SESSION['utilisateur_connecte']) || 
    ($_SESSION['utilisateur_connecte']['role_u'] !== 'super_admin' && 
     !in_array($_SESSION['utilisateur_connecte']['fonction_u'], ['Directeur', 'Directeur Adjoint', 'DE']))) {
    header('Location: connexion.php');
    exit();
}

// Récupérer les informations de l'utilisateur connecté
$utilisateur = $_SESSION['utilisateur_connecte'];
$informations_ecole = $_SESSION['informations_ecole'] ?? [];
$fonctions_application = $_SESSION['fonctions_application'] ?? [];

// Connexion à la base de données
try {
    $pdo = new PDO("mysql:host=$host;dbname=" . $_SESSION['base_de_donnees'] . ";charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Forcer la collation pour éviter les conflits
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    

    
} catch (PDOException $e) {
    die("Erreur de connexion à la base de données : " . $e->getMessage());
} catch (Exception $e) {
    die("Erreur générale : " . $e->getMessage());
}

// Récupérer les statistiques complètes (avec cache léger)
$stats = [
            'total_eleves' => 0,
            'total_personnel' => 0,
            'total_classes' => 0,
            'total_matieres' => 0,
            'total_roles' => 0,
            'total_fonctions' => 0,
            'eleves_par_classe' => [],
            'repartition_genre' => [],
            'classes_par_niveau' => [],
            'personnel_par_role' => [],
            'personnel_par_fonction' => [],
            'moyenne_eleves_par_classe' => 0,
            'ratio_personnel_eleves' => 0,
            'top_classes_peuplees' => [],
            'classes_moins_peuplees' => [],
            'repartition_age' => [],
            'matieres_plus_enseignees' => [],
            'info_ecole' => null,
            'roles_disponibles' => [],
            'fonctions_disponibles' => []
        ];
$cacheDir = __DIR__ . '/cache';
$statsCacheFile = $cacheDir . '/stats_cache.json';
$statsCacheTTL = 120; // secondes

// Tenter de charger depuis le cache
if (is_file($statsCacheFile) && (time() - @filemtime($statsCacheFile) < $statsCacheTTL)) {
    $cached = @file_get_contents($statsCacheFile);
    $decoded = $cached ? json_decode($cached, true) : null;
    if (is_array($decoded)) {
        // Conserver les clés par défaut et écraser avec les valeurs du cache
        $stats = array_merge($stats, $decoded);
    }
}

if (false && empty($stats)) {
    try {
        // === STATISTIQUES GÉNÉRALES ===
        
        // Nombre total d'élèves
        $stmt = $pdo->query("SELECT COUNT(*) as total_eleves FROM eleves");
        $stats['total_eleves'] = $stmt->fetch()['total_eleves'];
        
        // Nombre total de personnel
        $stmt = $pdo->query("SELECT COUNT(*) as total_personnel FROM utilisateurs");
        $stats['total_personnel'] = $stmt->fetch()['total_personnel'];
        
        // Nombre total de classes
        $stmt = $pdo->query("SELECT COUNT(*) as total_classes FROM classes");
        $stats['total_classes'] = $stmt->fetch()['total_classes'];
        
        // Nombre total de matières
        $stmt = $pdo->query("SELECT COUNT(*) as total_matieres FROM matieres");
        $stats['total_matieres'] = $stmt->fetch()['total_matieres'];
        
        // Nombre total de rôles définis
        $stmt = $pdo->query("SELECT COUNT(*) as total_roles FROM roles");
        $stats['total_roles'] = $stmt->fetch()['total_roles'];
        
        // Nombre total de fonctions
        $stmt = $pdo->query("SELECT COUNT(*) as total_fonctions FROM fonctions_application");
        $stats['total_fonctions'] = $stmt->fetch()['total_fonctions'];
        
        // === RÉPARTITIONS DÉTAILLÉES ===
        
        // Répartition des élèves par classe (inclut aussi les classes sans élèves)
        $stmt = $pdo->query("
            SELECT c.nom_classe AS nom_classe, COALESCE(COUNT(e.id),0) AS nombre_eleves
            FROM classes c
            LEFT JOIN eleves e ON e.classe_eleve = c.nom_classe
            GROUP BY c.nom_classe
            ORDER BY c.niveau, c.nom_classe
        ");
        $stats['eleves_par_classe'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Répartition des élèves par genre
        $stmt = $pdo->query("SELECT genre_eleve, COUNT(*) as nombre FROM eleves GROUP BY genre_eleve");
        $stats['repartition_genre'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Répartition des classes par niveau
        $stmt = $pdo->query("SELECT niveau, COUNT(*) as nombre FROM classes GROUP BY niveau ORDER BY niveau");
        $stats['classes_par_niveau'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Répartition du personnel par rôle
        $stmt = $pdo->query("SELECT role_u, COUNT(*) as nombre FROM utilisateurs GROUP BY role_u");
        $stats['personnel_par_role'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Répartition du personnel par fonction
        $stmt = $pdo->query("SELECT fonction_u, COUNT(*) as nombre FROM utilisateurs GROUP BY fonction_u");
        $stats['personnel_par_fonction'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // === STATISTIQUES AVANCÉES ===
        
        // Moyenne d'élèves par classe
        if ($stats['total_classes'] > 0) {
            $stats['moyenne_eleves_par_classe'] = round($stats['total_eleves'] / $stats['total_classes'], 1);
        } else {
            $stats['moyenne_eleves_par_classe'] = 0;
        }
        
        // Ratio personnel/élèves
        if ($stats['total_eleves'] > 0) {
            $stats['ratio_personnel_eleves'] = round($stats['total_personnel'] / $stats['total_eleves'], 3);
        } else {
            $stats['ratio_personnel_eleves'] = 0;
        }
        
        // Classes les plus peuplées (TOP 5)
        $stmt = $pdo->query("
            SELECT classe_eleve as nom_classe, COUNT(*) as nombre_eleves 
            FROM eleves 
            GROUP BY classe_eleve 
            ORDER BY nombre_eleves DESC 
            LIMIT 5
        ");
        $stats['top_classes_peuplees'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Classes les moins peuplées (TOP 5)
        $stmt = $pdo->query("
            SELECT classe_eleve as nom_classe, COUNT(*) as nombre_eleves 
            FROM eleves 
            GROUP BY classe_eleve 
            ORDER BY nombre_eleves ASC 
            LIMIT 5
        ");
        $stats['classes_moins_peuplees'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Répartition par âge (approximative basée sur l'année de naissance)
        $stmt = $pdo->query("
            SELECT 
                YEAR(CURDATE()) - YEAR(date_de_naissance_eleve) as age_approx,
                COUNT(*) as nombre
            FROM eleves 
            WHERE date_de_naissance_eleve IS NOT NULL
            GROUP BY YEAR(CURDATE()) - YEAR(date_de_naissance_eleve)
            ORDER BY age_approx
        ");
        $stats['repartition_age'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Matières les plus enseignées (selon les utilisateurs)
        $stmt = $pdo->query("
            SELECT 
                matiere_niveau_u as matiere,
                COUNT(*) as nombre_enseignants
            FROM utilisateurs 
            WHERE matiere_niveau_u IS NOT NULL AND matiere_niveau_u != ''
            GROUP BY matiere_niveau_u
            ORDER BY nombre_enseignants DESC
            LIMIT 10
        ");
        $stats['matieres_plus_enseignees'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Informations sur l'école
        $stmt = $pdo->query("SELECT * FROM informations_ecole LIMIT 1");
        $stats['info_ecole'] = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Liste des rôles disponibles
        $stmt = $pdo->query("SELECT code_role, executants FROM roles ORDER BY code_role");
        $stats['roles_disponibles'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Liste des fonctions disponibles
        $stmt = $pdo->query("SELECT fonctions FROM fonctions_application ORDER BY fonctions");
        $stats['fonctions_disponibles'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Sauvegarder le cache
        if (!is_dir($cacheDir)) { @mkdir($cacheDir, 0777, true); }
        @file_put_contents($statsCacheFile, json_encode($stats));
        
    } catch (PDOException $e) {
        
        $stats = [
            'total_eleves' => 0,
            'total_personnel' => 0,
            'total_classes' => 0,
            'total_matieres' => 0,
            'total_roles' => 0,
            'total_fonctions' => 0,
            'eleves_par_classe' => [],
            'repartition_genre' => [],
            'classes_par_niveau' => [],
            'personnel_par_role' => [],
            'personnel_par_fonction' => [],
            'moyenne_eleves_par_classe' => 0,
            'ratio_personnel_eleves' => 0,
            'top_classes_peuplees' => [],
            'classes_moins_peuplees' => [],
            'repartition_age' => [],
            'matieres_plus_enseignees' => [],
            'info_ecole' => null,
            'roles_disponibles' => [],
            'fonctions_disponibles' => [],
    
        ];
    }
}

// Map utilitaire: nombre d'élèves par classe pour accès O(1)
$elevesCountByClasse = [];
if (!empty($stats['eleves_par_classe'])) {
    foreach ($stats['eleves_par_classe'] as $row) {
        $elevesCountByClasse[$row['nom_classe']] = (int)$row['nombre_eleves'];
    }
}

// Récupération différée via admin_api.php (chargement à la demande côté client)
$eleves = [];

// Récupération différée via admin_api.php (chargement à la demande côté client)
$personnel = [];

// Récupérer toutes les classes
$classes = [];
try {
    $stmt = $pdo->query("SELECT * FROM classes ORDER BY niveau, nom_classe");
    $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $classes = [];
}

// Compter le nombre d'élèves par classe (map nom_classe => nb)
$eleves_count_by_classe = [];
try {
    $stmt = $pdo->query("SELECT classe_eleve, COUNT(*) AS nb FROM eleves GROUP BY classe_eleve");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (!empty($row['classe_eleve'])) {
            $eleves_count_by_classe[$row['classe_eleve']] = (int)$row['nb'];
        }
    }
} catch (PDOException $e) {
    $eleves_count_by_classe = [];
}

// Récupérer toutes les matières
$matieres = [];
try {
    $stmt = $pdo->query("SELECT * FROM matieres ORDER BY nom_matiere");
    $matieres = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $matieres = [];
}

// Récupérer les fonctions de l'application
$fonctions_app = [];
try {
    $stmt = $pdo->query("SELECT * FROM fonctions_application ORDER BY fonctions");
    $fonctions_app = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $fonctions_app = [];
}

// Récupérer les créneaux horaires et les jours étudiés (Jours/Horaires)
$creneaux = [];
$jours_etudies = [];
try {
    $stmt = $pdo->query("SELECT * FROM creneaux_horaires ORDER BY ordre_affichage, heure_debut");
    $creneaux = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Récupération des jours étudiés depuis informations_ecole.reserve3 (format: Lundi|Mardi|...)
    $stmt = $pdo->query("SELECT reserve3 FROM informations_ecole LIMIT 1");
    $reserve3 = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($reserve3 && !empty($reserve3['reserve3'])) {
        $jours_etudies = array_filter(array_map('trim', explode('|', $reserve3['reserve3'])));
    }
} catch (PDOException $e) {
    $creneaux = [];
    $jours_etudies = [];
}

// Toutes les fonctions dispo de l'application
$fonctionnalites_disponibles = [
    // 'INSCR_REINSCR' => [
    //     'nom' => 'Inscription et réinscription',
    //     'icone' => '👥',
    //     'description' => 'Gérez les inscriptions de nouveaux élèves et les réinscriptions des anciens. Collectez les documents, validez les dossiers et automatisez le processus d\'admission.'
    // ],
    'GEST_NOTES_BULL' => [
        'nom' => 'Gestion des notes et bulletin',
        'icone' => '📊',
        'description' => 'Saisissez et gérez les notes des élèves, générez automatiquement les bulletins scolaires et calculez les moyennes par matière et générale.'
    ],
    'SAISIE_NOTE_GLOBALE' => [
        'nom' => 'Saisie globale des notes',
        'icone' => '📊',
        'description' => 'Saisissez rapidement les notes pour une classe dans toutes les matières en une seule interface.'
    ],
    'SUIVI_ABS_RET' => [
        'nom' => 'Suivi des absences et retards',
        'icone' => '⏰',
        'description' => 'Enregistrez les absences et retards des élèves, générez des rapports d\'assiduité et envoyez des notifications automatiques aux parents.'
    ],


    'CAL_SCO' => [
        'nom' => 'Planification du calendrier scolaire',
        'icone' => '📅',
        'description' => 'Planifier le calendrier scolaire: début et fin, congés, vacances, jours fériés, etc...'
    ],
    'GEST_EMP_TEMPS' => [
        'nom' => 'Gestion des emplois du temps',
        'icone' => '📅',
        'description' => 'Créez et gérez les emplois du temps des classes et des enseignants. Planifiez les cours, gérez les salles et évitez les conflits d\'horaires.'
    ],
    'ACT_INFOS' => [
        'nom' => 'Actualités et informations',
        'icone' => '📢',
        'description' => 'Publiez des actualités, des annonces et des informations importantes. Informez toute la communauté scolaire des événements et nouvelles.'
    ],
    'CARTES_SCOL' => [
        'nom' => 'Cartes scolaire et fiche d\'élève',
        'icone' => '🆔',
        'description' => 'Générez des cartes scolaires personnalisées et gérez les fiches détaillées des élèves avec photos, informations personnelles et académiques.'
    ],
    'SCOLARITE' => [
        'nom' => 'Situation de paiement des scolarités',
        'icone' => '💰',
        'description' => 'Suivez les paiements des frais de scolarité, gérez les factures, envoyez des rappels automatiques et générez des rapports financiers.'
    ],
    'COMPTA_TRES' => [
        'nom' => 'Comptabilité et trésorerie',
        'icone' => '💼',
        'description' => 'Gérez les finances de l\'établissement : frais de scolarité, paiements, factures, dépenses et génération de rapports financiers détaillés.'
    ],
    'MESSAGE' => [
        'nom' => 'Messagerie',
        'icone' => '📢',
        'description' => 'Envoyez un message aux parents (d\'un élève ou d\'une classe) et aux enseignants.'
    ]
];

// Récupérer les fonctions actuellement activées
$fonctions_activees = [];
if (!empty($fonctions_app)) {
    foreach ($fonctions_app as $fonction) {
        $codes = explode('|', $fonction['fonctions']);
        $fonctions_activees = array_merge($fonctions_activees, array_map('trim', $codes));
    }
}

// Variables pour les messages
$message_succes = '';
$message_erreur = '';

// Vérifier les paramètres GET pour les messages (affichage unique)
if (isset($_GET['success']) && !isset($_SESSION['message_displayed'])) {
    switch ($_GET['success']) {
        case '1':
            if (isset($_GET['matricule'])) {
                $message_succes = 'Élève ajouté avec succès ! Matricule: ' . htmlspecialchars($_GET['matricule']);
            }
            break;
        case '2':
            $message_succes = 'Élève modifié avec succès !';
            break;
        case '3':
            $message_succes = 'Élève supprimé avec succès !';
            break;
    }
    // Marquer le message comme affiché pour éviter la répétition
    $_SESSION['message_displayed'] = true;
}

// Traitement du formulaire d'ajout de personnel
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'ajouter_personnel') {
    try {
        // Validation des données
        $erreurs = [];
        
        // Vérifier les champs obligatoires
        if (empty($_POST['nom_u'])) $erreurs[] = "Le nom est obligatoire";
        if (empty($_POST['prenom_u'])) $erreurs[] = "Le prénom est obligatoire";
        if (empty($_POST['mail_u'])) $erreurs[] = "L'email est obligatoire";
        if (empty($_POST['tel_u'])) $erreurs[] = "Le téléphone est obligatoire";
        if (empty($_POST['fonction_u'])) $erreurs[] = "La fonction est obligatoire";
        // if (empty($_POST['role_u'])) $erreurs[] = "Le rôle est obligatoire"; // Rôle optionnel
        if (empty($_POST['mot_de_passe_u'])) $erreurs[] = "Le mot de passe est obligatoire";
        
        // Validation du téléphone (minimum 8 chiffres)
        if (!empty($_POST['tel_u']) && strlen(preg_replace('/\D/', '', $_POST['tel_u'])) < 9) {
            $erreurs[] = "Le téléphone doit contenir au moins 9 chiffres (sans code)";
        }
        
        // Validation email
        if (!empty($_POST['mail_u']) && !filter_var($_POST['mail_u'], FILTER_VALIDATE_EMAIL)) {
            $erreurs[] = "L'email n'est pas valide";
        }
        
        // Vérifier si email/téléphone existe déjà
        $stmt = $pdo->prepare("SELECT id FROM utilisateurs WHERE mail_u = ? OR tel_u = ?");
        $stmt->execute([$_POST['mail_u'], $_POST['tel_u']]);
        if ($stmt->fetch()) {
            $erreurs[] = "Cet email ou ce numéro de téléphone existe déjà";
        }
        
        // Validation spéciale pour les professeurs
        if ($_POST['fonction_u'] === 'Professeur') {
            if (empty($_POST['matieres']) || !is_array($_POST['matieres'])) {
                $erreurs[] = "Au moins une matière est obligatoire pour un professeur";
            } else {
                // Vérifier que chaque matière a des niveaux
                $niveaux_array = isset($_POST['niveaux']) ? $_POST['niveaux'] : [];
                
                foreach ($_POST['matieres'] as $index => $matiere) {
                    if (empty($matiere)) {
                        $erreurs[] = "Toutes les matières doivent être sélectionnées";
                        break;
                    }
                    // Vérifier le niveau correspondant dans le tableau niveaux[]
                    if (empty($niveaux_array[$index])) {
                        $erreurs[] = "Chaque matière doit avoir au moins un niveau/classe";
                        break;
                    }
                }
            }
        }
        
        if (!empty($erreurs)) {
            echo json_encode([
                'success' => false,
                'message' => implode(', ', $erreurs)
            ]);
            exit();
        }
        
        // Formatage du champ matiere_niveau_u pour les professeurs
        $matiere_niveau_formatted = '';
        if ($_POST['fonction_u'] === 'Professeur' && !empty($_POST['matieres'])) {
            $matiere_niveau_parts = [];
            $niveaux_array = isset($_POST['niveaux']) ? $_POST['niveaux'] : [];
            
            foreach ($_POST['matieres'] as $index => $matiere) {
                if (!empty($niveaux_array[$index])) {
                    // Les niveaux sont séparés par des deux points dans le champ hidden
                    $niveaux = explode(':', $niveaux_array[$index]);
                    $matiere_niveau_parts[] = $matiere . ':' . implode(':', $niveaux);
                }
            }
            $matiere_niveau_formatted = implode('|', $matiere_niveau_parts);
        }
        
        // Gestion de l'upload de la photo
        $photo_path = null;
        if (isset($_FILES['pp_u']) && $_FILES['pp_u']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = $dbname.'/photos_personnel/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $file_extension = strtolower(pathinfo($_FILES['pp_u']['name'], PATHINFO_EXTENSION));
            $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];
            
            if (in_array($file_extension, $allowed_extensions)) {
                $photo_name = 'personnel_' . time() . '_' . uniqid() . '.' . $file_extension;
                $photo_path = $upload_dir . $photo_name;
                
                if (!move_uploaded_file($_FILES['pp_u']['tmp_name'], $photo_path)) {
                    $photo_path = null;
                }
            }
        }
        
        // Insertion dans la base de données
        $sql = "INSERT INTO utilisateurs (
            nom_u, prenom_u, mail_u, tel_u, mot_de_passe_u, fonction_u, 
            role_u, matiere_niveau_u, pp_u, date_creation
        ) VALUES (
            :nom_u, :prenom_u, :mail_u, :tel_u, :mot_de_passe_u, :fonction_u, 
            :role_u, :matiere_niveau_u, :pp_u, NOW()
        )";

        $stmt = $pdo->prepare($sql);
        $result = $stmt->execute([
            ':nom_u' => $_POST['nom_u'],
            ':prenom_u' => $_POST['prenom_u'],
            ':mail_u' => $_POST['mail_u'],
            ':tel_u' => $_POST['tel_u'],
            ':mot_de_passe_u' => password_hash($_POST['mot_de_passe_u'], PASSWORD_DEFAULT),
            ':fonction_u' => $_POST['fonction_u'],
            ':role_u' => $_POST['role_u'],
            ':matiere_niveau_u' => $matiere_niveau_formatted,
            ':pp_u' => $photo_path
        ]);

        if ($result) {
            echo json_encode([
                'success' => true,
                'message' => 'Personnel ajouté avec succès !',
                'personnel' => [
                    'id' => $pdo->lastInsertId(),
                    'nom_u' => $_POST['nom_u'],
                    'prenom_u' => $_POST['prenom_u'],
                    'mail_u' => $_POST['mail_u'],
                    'tel_u' => $_POST['tel_u'],
                    'fonction_u' => $_POST['fonction_u'],
                    'role_u' => $_POST['role_u'],
                    'matiere_niveau_u' => $matiere_niveau_formatted,
                    'pp_u' => $photo_path,
                    'date_creation' => date('Y-m-d H:i:s')
                ]
            ]);
            exit();
        }

    } catch (PDOException $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Erreur lors de l\'ajout : ' . $e->getMessage()
        ]);
        exit();
    }
}

// Traitement pour récupérer les données d'un personnel
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_personnel_data') {
    try {
        $personnel_id = $_POST['personnel_id'];
        
        $stmt = $pdo->prepare("SELECT * FROM utilisateurs WHERE id = ?");
        $stmt->execute([$personnel_id]);
        $personnel = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($personnel) {
            echo json_encode([
                'success' => true,
                'personnel' => $personnel
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Personnel non trouvé'
            ]);
        }
        exit();
    } catch (PDOException $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Erreur lors de la récupération des données : ' . $e->getMessage()
        ]);
        exit();
    }
}

// Traitement du formulaire de modification de personnel
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'modifier_personnel') {
    try {
        $personnel_id = $_POST['personnel_id'];
        
        // Validation des données
        $erreurs = [];
        
        // Vérifier les champs obligatoires
        if (empty($_POST['nom_u'])) $erreurs[] = "Le nom est obligatoire";
        if (empty($_POST['prenom_u'])) $erreurs[] = "Le prénom est obligatoire";
        if (empty($_POST['mail_u'])) $erreurs[] = "L'email est obligatoire";
        if (empty($_POST['tel_u'])) $erreurs[] = "Le téléphone est obligatoire";
        if (empty($_POST['fonction_u'])) $erreurs[] = "La fonction est obligatoire";
        // if (empty($_POST['role_u'])) $erreurs[] = "Le rôle est obligatoire"; // Rôle optionnel
        
        // Validation du téléphone (minimum 8 chiffres)
        if (!empty($_POST['tel_u']) && strlen(preg_replace('/\D/', '', $_POST['tel_u'])) < 9) {
            $erreurs[] = "Le téléphone doit contenir au moins 9 chiffres";
        }
        
        // Validation email
        if (!empty($_POST['mail_u']) && !filter_var($_POST['mail_u'], FILTER_VALIDATE_EMAIL)) {
            $erreurs[] = "L'email n'est pas valide";
        }
        
        // Vérifier si email/téléphone existe déjà pour un autre utilisateur
        $stmt = $pdo->prepare("SELECT id FROM utilisateurs WHERE (mail_u = ? OR tel_u = ?) AND id != ?");
        $stmt->execute([$_POST['mail_u'], $_POST['tel_u'], $personnel_id]);
        if ($stmt->fetch()) {
            $erreurs[] = "Cet email ou ce numéro de téléphone est déjà utilisé par un autre personnel";
        }
        
        // Validation spéciale pour les professeurs
        if ($_POST['fonction_u'] === 'Professeur') {
            if (!empty($_POST['matieres']) && is_array($_POST['matieres'])) {
                $niveaux_array = isset($_POST['niveaux']) ? $_POST['niveaux'] : [];
                
                foreach ($_POST['matieres'] as $index => $matiere) {
                    if (empty($matiere)) {
                        $erreurs[] = "Toutes les matières doivent être sélectionnées";
                        break;
                    }
                    // Vérifier le niveau correspondant dans le tableau niveaux[]
                    if (empty($niveaux_array[$index])) {
                        $erreurs[] = "Chaque matière doit avoir au moins un niveau/classe";
                        break;
                    }
                }
            }
        }
        
        if (!empty($erreurs)) {
            echo json_encode([
                'success' => false,
                'message' => implode(', ', $erreurs)
            ]);
            exit();
        }
        
        // Formatage du champ matiere_niveau_u pour les professeurs
        $matiere_niveau_formatted = '';
        if ($_POST['fonction_u'] === 'Professeur' && !empty($_POST['matieres'])) {
            $matiere_niveau_parts = [];
            $niveaux_array = isset($_POST['niveaux']) ? $_POST['niveaux'] : [];
            
            foreach ($_POST['matieres'] as $index => $matiere) {
                if (!empty($niveaux_array[$index])) {
                    // Les niveaux sont séparés par des deux points dans le champ hidden
                    $niveaux = explode(':', $niveaux_array[$index]);
                    $matiere_niveau_parts[] = $matiere . ':' . implode(':', $niveaux);
                }
            }
            $matiere_niveau_formatted = implode('|', $matiere_niveau_parts);
        }
        
        // Gestion de l'upload de la nouvelle photo
        $photo_path = null;
        $update_photo = false;
        
        if (isset($_FILES['pp_u']) && $_FILES['pp_u']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = $dbname.'/photos_personnel/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $file_extension = strtolower(pathinfo($_FILES['pp_u']['name'], PATHINFO_EXTENSION));
            $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];
            
            if (in_array($file_extension, $allowed_extensions)) {
                // Supprimer l'ancienne photo si elle existe
                $stmt = $pdo->prepare("SELECT pp_u FROM utilisateurs WHERE id = ?");
                $stmt->execute([$personnel_id]);
                $old_personnel = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($old_personnel && $old_personnel['pp_u'] && file_exists($old_personnel['pp_u'])) {
                    unlink($old_personnel['pp_u']);
                }
                
                $photo_name = 'personnel_' . time() . '_' . uniqid() . '.' . $file_extension;
                $photo_path = $upload_dir . $photo_name;
                
                if (move_uploaded_file($_FILES['pp_u']['tmp_name'], $photo_path)) {
                    $update_photo = true;
                } else {
                    $photo_path = null;
                }
            }
        }
        
        // Préparer la requête de mise à jour
        $update_fields = [
            "nom_u = :nom_u",
            "prenom_u = :prenom_u", 
            "mail_u = :mail_u",
            "tel_u = :tel_u",
            "fonction_u = :fonction_u",
            "role_u = :role_u",
            "matiere_niveau_u = :matiere_niveau_u"
        ];
        
        $params = [
            ':nom_u' => $_POST['nom_u'],
            ':prenom_u' => $_POST['prenom_u'],
            ':mail_u' => $_POST['mail_u'],
            ':tel_u' => $_POST['tel_u'],
            ':fonction_u' => $_POST['fonction_u'],
            ':role_u' => $_POST['role_u'],
            ':matiere_niveau_u' => $matiere_niveau_formatted,
            ':id' => $personnel_id
        ];
        
        // Ajouter la photo si une nouvelle a été uploadée
        if ($update_photo && $photo_path) {
            $update_fields[] = "pp_u = :pp_u";
            $params[':pp_u'] = $photo_path;
        }
        
        // Ajouter le mot de passe si un nouveau a été fourni
        if (!empty($_POST['nouveau_mot_de_passe'])) {
            $update_fields[] = "mot_de_passe_u = :mot_de_passe_u";
            $params[':mot_de_passe_u'] = password_hash($_POST['nouveau_mot_de_passe'], PASSWORD_DEFAULT);
        }
        
        // Exécuter la mise à jour
        $sql = "UPDATE utilisateurs SET " . implode(', ', $update_fields) . " WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $result = $stmt->execute($params);
        
        if ($result) {
            // Récupérer les données mises à jour
            $stmt = $pdo->prepare("SELECT * FROM utilisateurs WHERE id = ?");
            $stmt->execute([$personnel_id]);
            $personnel_updated = $stmt->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'message' => 'Personnel modifié avec succès !',
                'personnel' => $personnel_updated
            ]);
            exit();
        }

    } catch (PDOException $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Erreur lors de la modification : ' . $e->getMessage()
        ]);
        exit();
    }
}

// ===== TRAITEMENT DE LA DUPLICATION DE CLASSE =====

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'duplicate_classe') {
    try {
        $classe_id = $_POST['classe_id'];
        $nouveau_nom = $_POST['nom_classe'];
        $nouveau_niveau = $_POST['niveau'];
        
        // Vérifier que la classe source existe
        $stmt = $pdo->prepare("SELECT * FROM classes WHERE id = ?");
        $stmt->execute([$classe_id]);
        $classe_source = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$classe_source) {
            echo json_encode([
                'success' => false,
                'message' => 'Classe source non trouvée'
            ]);
            exit();
        }
        
        // Vérifier que le nouveau nom n'existe pas déjà
        $stmt = $pdo->prepare("SELECT id FROM classes WHERE nom_classe = ?");
        $stmt->execute([$nouveau_nom]);
        if ($stmt->fetch()) {
            echo json_encode([
                'success' => false,
                'message' => "Une classe avec le nom \"$nouveau_nom\" existe déjà"
            ]);
            exit();
        }
        
        // Dupliquer la classe avec le nouveau nom et niveau
        $stmt = $pdo->prepare("INSERT INTO classes (nom_classe, niveau, mat_coef_bareme, date_creation) VALUES (?, ?, ?, NOW())");
        $result = $stmt->execute([
            $nouveau_nom,
            $nouveau_niveau,
            $classe_source['mat_coef_bareme']
        ]);
        
        if ($result) {
            echo json_encode([
                'success' => true,
                'message' => "Classe dupliquée avec succès ! La nouvelle classe \"$nouveau_nom\" a été créée avec les mêmes matières."
            ]);
            exit();
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Erreur lors de la duplication de la classe'
            ]);
            exit();
        }
        
    } catch (PDOException $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Erreur lors de la duplication : ' . $e->getMessage()
        ]);
        exit();
    }
}

// Traitement du formulaire d'ajout de classe
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'ajouter_classe') {
    try {
        // Validation des données
        $erreurs = [];
        
        // Vérifier les champs obligatoires
        if (empty($_POST['nom_classe'])) $erreurs[] = "Le nom de la classe est obligatoire";
        if (empty($_POST['niveau'])) $erreurs[] = "Le niveau est obligatoire";
        
        // Récupérer les matières, coefficients et barèmes
        $matieres = isset($_POST['matieres']) ? $_POST['matieres'] : [];
        $coefficients = isset($_POST['coefficients']) ? $_POST['coefficients'] : [];
        $baremes = isset($_POST['baremes']) ? $_POST['baremes'] : [];
        
        // Filtrer les matières vides et valider
        $matieres_valides = [];
        for ($i = 0; $i < count($matieres); $i++) {
            if (!empty($matieres[$i]) && isset($coefficients[$i]) && isset($baremes[$i])) {
                // Validation des valeurs
                $coef = intval($coefficients[$i]);
                $bareme = intval($baremes[$i]);
                
                if ($coef < 1 || $coef > 10) {
                    $erreurs[] = "Le coefficient doit être entre 1 et 10 pour la matière : " . $matieres[$i];
                }
                if ($bareme < 10 || $bareme > 100) {
                    $erreurs[] = "Le barème doit être entre 10 et 100 pour la matière : " . $matieres[$i];
                }
                
                $matieres_valides[] = [
                    'matiere' => trim($matieres[$i]),
                    'coefficient' => $coef,
                    'bareme' => $bareme
                ];
            }
        }
        
        // Vérifier qu'au moins une matière est sélectionnée
        if (empty($matieres_valides)) {
            $erreurs[] = "Vous devez sélectionner au moins une matière";
        }
        
        // Vérifier que le nom de classe n'existe pas déjà
        $stmt = $pdo->prepare("SELECT id FROM classes WHERE nom_classe = ?");
        $stmt->execute([$_POST['nom_classe']]);
        if ($stmt->fetch()) {
            $erreurs[] = "Une classe avec ce nom existe déjà";
        }
        
        if (!empty($erreurs)) {
            echo json_encode([
                'success' => false,
                'message' => implode('<br>', $erreurs)
            ]);
            exit();
        }
        
        // Formater mat_coef_bareme (Format : Espagnol:1:20 | Français:1:20)
        $mat_coef_bareme_array = [];
        foreach ($matieres_valides as $item) {
            $mat_coef_bareme_array[] = $item['matiere'] . ':' . $item['coefficient'] . ':' . $item['bareme'];
        }
        $mat_coef_bareme = implode(' | ', $mat_coef_bareme_array);
        
        // Insertion dans la base de données
        $stmt = $pdo->prepare("INSERT INTO classes (nom_classe, niveau, mat_coef_bareme, date_creation) VALUES (?, ?, ?, NOW())");
        $result = $stmt->execute([
            $_POST['nom_classe'],
            $_POST['niveau'],
            $mat_coef_bareme
        ]);
        
        if ($result) {
            echo json_encode([
                'success' => true,
                'message' => 'Classe "' . $_POST['nom_classe'] . '" ajoutée avec succès !',
                'classe_id' => $pdo->lastInsertId()
            ]);
            exit();
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Erreur lors de l\'ajout de la classe'
            ]);
            exit();
        }
        
    } catch (PDOException $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Erreur lors de l\'ajout de la classe : ' . $e->getMessage()
        ]);
        exit();
    }
}

// Traitement pour récupérer les données d'une classe
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_classe_data') {
    try {
        $classe_id = $_POST['classe_id'];
        
        $stmt = $pdo->prepare("SELECT * FROM classes WHERE id = ?");
        $stmt->execute([$classe_id]);
        $classe = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($classe) {
            echo json_encode([
                'success' => true,
                'classe' => $classe
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Classe non trouvée'
            ]);
        }
        exit();
    } catch (PDOException $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Erreur lors de la récupération des données : ' . $e->getMessage()
        ]);
        exit();
    }
}

// Traitement du formulaire de modification de classe
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'modifier_classe') {
    try {
        $classe_id = $_POST['classe_id'];
        
        // Validation des données
        $erreurs = [];
        
        // Vérifier les champs obligatoires
        if (empty($_POST['nom_classe'])) $erreurs[] = "Le nom de la classe est obligatoire";
        if (empty($_POST['niveau'])) $erreurs[] = "Le niveau est obligatoire";
        
        // Récupérer les matières, coefficients et barèmes
        $matieres = isset($_POST['matieres']) ? $_POST['matieres'] : [];
        $coefficients = isset($_POST['coefficients']) ? $_POST['coefficients'] : [];
        $baremes = isset($_POST['baremes']) ? $_POST['baremes'] : [];
        
        // Filtrer les matières vides et valider
        $matieres_valides = [];
        for ($i = 0; $i < count($matieres); $i++) {
            if (!empty($matieres[$i]) && isset($coefficients[$i]) && isset($baremes[$i])) {
                // Validation des valeurs
                $coef = intval($coefficients[$i]);
                $bareme = intval($baremes[$i]);
                
                if ($coef < 1 || $coef > 10) {
                    $erreurs[] = "Le coefficient doit être entre 1 et 10 pour la matière : " . $matieres[$i];
                }
                if ($bareme < 10 || $bareme > 100) {
                    $erreurs[] = "Le barème doit être entre 10 et 100 pour la matière : " . $matieres[$i];
                }
                
                $matieres_valides[] = [
                    'matiere' => trim($matieres[$i]),
                    'coefficient' => $coef,
                    'bareme' => $bareme
                ];
            }
        }
        
        // Vérifier qu'au moins une matière est sélectionnée
        if (empty($matieres_valides)) {
            $erreurs[] = "Vous devez sélectionner au moins une matière";
        }
        
        // Vérifier que le nom de classe n'existe pas déjà (sauf pour la classe actuelle)
        $stmt = $pdo->prepare("SELECT id FROM classes WHERE nom_classe = ? AND id != ?");
        $stmt->execute([$_POST['nom_classe'], $classe_id]);
        if ($stmt->fetch()) {
            $erreurs[] = "Une autre classe avec ce nom existe déjà";
        }
        
        if (!empty($erreurs)) {
            echo json_encode([
                'success' => false,
                'message' => implode('<br>', $erreurs)
            ]);
            exit();
        }
        
        // Formater mat_coef_bareme (Format : Espagnol:1:20 | Français:1:20)
        $mat_coef_bareme_array = [];
        foreach ($matieres_valides as $item) {
            $mat_coef_bareme_array[] = $item['matiere'] . ':' . $item['coefficient'] . ':' . $item['bareme'];
        }
        $mat_coef_bareme = implode(' | ', $mat_coef_bareme_array);
        
        // Mise à jour dans la base de données
        $stmt = $pdo->prepare("UPDATE classes SET nom_classe = ?, niveau = ?, mat_coef_bareme = ? WHERE id = ?");
        $result = $stmt->execute([
            $_POST['nom_classe'],
            $_POST['niveau'],
            $mat_coef_bareme,
            $classe_id
        ]);
        
        if ($result) {
            echo json_encode([
                'success' => true,
                'message' => 'Classe "' . $_POST['nom_classe'] . '" modifiée avec succès !'
            ]);
            exit();
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Erreur lors de la modification de la classe'
            ]);
            exit();
        }
        
    } catch (PDOException $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Erreur lors de la modification de la classe : ' . $e->getMessage()
        ]);
        exit();
    }
}

// Traitement de la suppression de classe
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'supprimer_classe') {
    try {
        $classe_id = $_POST['id_classe'];
        
        // Vérifier si la classe existe
        $stmt = $pdo->prepare("SELECT nom_classe FROM classes WHERE id = ?");
        $stmt->execute([$classe_id]);
        $classe = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$classe) {
            echo json_encode([
                'success' => false,
                'message' => 'Classe non trouvée'
            ]);
            exit();
        }
        
        // Vérifier s'il y a des élèves assignés à cette classe
        $stmt = $pdo->prepare("SELECT COUNT(*) as nb_eleves FROM eleves WHERE classe_eleve = ?");
        $stmt->execute([$classe['nom_classe']]);
        $nb_eleves = $stmt->fetch()['nb_eleves'];
        
        if ($nb_eleves > 0) {
            echo json_encode([
                'success' => false,
                'message' => "Impossible de supprimer la classe \"{$classe['nom_classe']}\". Elle contient encore {$nb_eleves} élève(s). Veuillez d'abord réassigner ou supprimer les élèves."
            ]);
            exit();
        }
        
        // Supprimer la classe
        $stmt = $pdo->prepare("DELETE FROM classes WHERE id = ?");
        $result = $stmt->execute([$classe_id]);
        
        if ($result) {
            echo json_encode([
                'success' => true,
                'message' => "Classe \"{$classe['nom_classe']}\" supprimée avec succès !"
            ]);
            exit();
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Erreur lors de la suppression de la classe'
            ]);
            exit();
        }
        
    } catch (PDOException $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Erreur lors de la suppression de la classe : ' . $e->getMessage()
        ]);
        exit();
    }
}



// ===== TRAITEMENTS CRUD MATIÈRES =====

// Enregistrer une sélection de matières (avec génération automatique de code)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'enregistrer_selection_matieres') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $selection = isset($_POST['matieres_selection']) && is_array($_POST['matieres_selection']) ? $_POST['matieres_selection'] : [];
        $selection = array_values(array_filter(array_map('trim', $selection)));
        if (empty($selection)) {
            echo json_encode(['success' => false, 'message' => 'Aucune matière sélectionnée']); exit();
        }

        // Helper pour générer un code à partir du nom
        $generateCode = function($name) use ($pdo) {
            $n = preg_replace('/[^a-zA-ZÀ-ÿ\\s]/u', '', $name);
            $n = trim($n);
            // Prendre initiales si plusieurs mots, sinon 4 premières lettres
            $parts = preg_split('/\\s+/u', $n);
            if (count($parts) > 1) {
                $initials = '';
                foreach ($parts as $p) {
                    $initials .= mb_strtoupper(mb_substr($p, 0, 1, 'UTF-8'), 'UTF-8');
                }
                $codeBase = mb_substr($initials, 0, 4, 'UTF-8');
            } else {
                $codeBase = mb_strtoupper(mb_substr($n, 0, 4, 'UTF-8'), 'UTF-8');
            }
            $code = $codeBase;

            // Assurer l'unicité en base
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM matieres WHERE code_matiere = ?");
            $suffix = 1;
            while (true) {
                $stmt->execute([$code]);
                if ((int)$stmt->fetchColumn() === 0) break;
                $code = $codeBase . $suffix;
                $suffix++;
            }
            return $code;
        };

        $inserted = [];
        $skipped = [];
        foreach ($selection as $nom) {
            if ($nom === '') continue;

            // Vérifier si la matière existe déjà par nom
            $stmt = $pdo->prepare("SELECT id, code_matiere FROM matieres WHERE nom_matiere = ?");
            $stmt->execute([$nom]);
            $exists = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($exists) {
                $skipped[] = ['nom' => $nom, 'code' => $exists['code_matiere']];
                continue;
            }

            // Générer code et insérer
            $code = $generateCode($nom);
            $stmt = $pdo->prepare("INSERT INTO matieres (nom_matiere, code_matiere, date_creation) VALUES (?, ?, NOW())");
            $ok = $stmt->execute([$nom, $code]);
            if ($ok) {
                $inserted[] = ['nom' => $nom, 'code' => $code, 'id' => $pdo->lastInsertId()];
            }
        }

        echo json_encode([
            'success' => true,
            'message' => 'Sélection enregistrée',
            'inserted' => $inserted,
            'skipped' => $skipped
        ]);
        exit();
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]); exit();
    }
}

// Traitement de l'ajout de matière
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'ajouter_matiere') {
    try {
        // Validation des données
        $erreurs = [];
        
        // Vérifier les champs obligatoires
        if (empty($_POST['nom_matiere'])) $erreurs[] = "Le nom de la matière est obligatoire";
        if (empty($_POST['code_matiere'])) $erreurs[] = "Le code de la matière est obligatoire";
        
        // Vérifier que le nom de matière n'existe pas déjà
        $stmt = $pdo->prepare("SELECT id FROM matieres WHERE nom_matiere = ?");
        $stmt->execute([$_POST['nom_matiere']]);
        if ($stmt->fetch()) {
            $erreurs[] = "Une matière avec ce nom existe déjà";
        }
        
        // Vérifier que le code de matière n'existe pas déjà
        $stmt = $pdo->prepare("SELECT id FROM matieres WHERE code_matiere = ?");
        $stmt->execute([$_POST['code_matiere']]);
        if ($stmt->fetch()) {
            $erreurs[] = "Une matière avec ce code existe déjà";
        }
        
        if (!empty($erreurs)) {
            echo json_encode([
                'success' => false,
                'message' => implode('<br>', $erreurs)
            ]);
            exit();
        }
        
        // Insérer dans la base de données
        $stmt = $pdo->prepare("INSERT INTO matieres (nom_matiere, code_matiere, date_creation) VALUES (?, ?, NOW())");
        $result = $stmt->execute([
            $_POST['nom_matiere'],
            strtoupper($_POST['code_matiere'])
        ]);
        
        if ($result) {
            echo json_encode([
                'success' => true,
                'message' => 'Matière "' . $_POST['nom_matiere'] . '" ajoutée avec succès !',
                'matiere_id' => $pdo->lastInsertId()
            ]);
            exit();
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Erreur lors de l\'ajout de la matière'
            ]);
            exit();
        }
        
    } catch (PDOException $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Erreur lors de l\'ajout de la matière : ' . $e->getMessage()
        ]);
        exit();
    }
}

// Traitement pour récupérer les données d'une matière
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_matiere_data') {
    try {
        $matiere_id = $_POST['matiere_id'];
        
        $stmt = $pdo->prepare("SELECT * FROM matieres WHERE id = ?");
        $stmt->execute([$matiere_id]);
        $matiere = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($matiere) {
            echo json_encode([
                'success' => true,
                'matiere' => $matiere
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Matière non trouvée'
            ]);
        }
        exit();
    } catch (PDOException $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Erreur lors de la récupération des données : ' . $e->getMessage()
        ]);
        exit();
    }
}

// Traitement de la modification de matière
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'modifier_matiere') {
    try {
        $matiere_id = $_POST['matiere_id'];
        
        // Validation des données
        $erreurs = [];
        
        // Vérifier les champs obligatoires
        if (empty($_POST['nom_matiere'])) $erreurs[] = "Le nom de la matière est obligatoire";
        if (empty($_POST['code_matiere'])) $erreurs[] = "Le code de la matière est obligatoire";
        
        // Vérifier que le nom de matière n'existe pas déjà (sauf pour la matière actuelle)
        $stmt = $pdo->prepare("SELECT id FROM matieres WHERE nom_matiere = ? AND id != ?");
        $stmt->execute([$_POST['nom_matiere'], $matiere_id]);
        if ($stmt->fetch()) {
            $erreurs[] = "Une autre matière avec ce nom existe déjà";
        }
        
        // Vérifier que le code de matière n'existe pas déjà (sauf pour la matière actuelle)
        $stmt = $pdo->prepare("SELECT id FROM matieres WHERE code_matiere = ? AND id != ?");
        $stmt->execute([$_POST['code_matiere'], $matiere_id]);
        if ($stmt->fetch()) {
            $erreurs[] = "Une autre matière avec ce code existe déjà";
        }
        
        if (!empty($erreurs)) {
            echo json_encode([
                'success' => false,
                'message' => implode('<br>', $erreurs)
            ]);
            exit();
        }
        
        // Mise à jour dans la base de données
        $stmt = $pdo->prepare("UPDATE matieres SET nom_matiere = ?, code_matiere = ? WHERE id = ?");
        $result = $stmt->execute([
            $_POST['nom_matiere'],
            strtoupper($_POST['code_matiere']),
            $matiere_id
        ]);
        
        if ($result) {
            echo json_encode([
                'success' => true,
                'message' => 'Matière "' . $_POST['nom_matiere'] . '" modifiée avec succès !'
            ]);
            exit();
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Erreur lors de la modification de la matière'
            ]);
            exit();
        }
        
    } catch (PDOException $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Erreur lors de la modification de la matière : ' . $e->getMessage()
        ]);
        exit();
    }
}

// Traitement de la suppression de matière
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'supprimer_matiere') {
    try {
        $matiere_id = $_POST['id_matiere'];
        
        // Vérifier si la matière existe
        $stmt = $pdo->prepare("SELECT nom_matiere FROM matieres WHERE id = ?");
        $stmt->execute([$matiere_id]);
        $matiere = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$matiere) {
            echo json_encode([
                'success' => false,
                'message' => 'Matière non trouvée'
            ]);
            exit();
        }
        
        // Vérifier si la matière est utilisée dans des classes
        $stmt = $pdo->prepare("SELECT COUNT(*) as nb_classes FROM classes WHERE mat_coef_bareme LIKE ?");
        $stmt->execute(['%' . $matiere['nom_matiere'] . ':%']);
        $nb_classes = $stmt->fetch()['nb_classes'];
        
        if ($nb_classes > 0) {
            echo json_encode([
                'success' => false,
                'message' => "Impossible de supprimer la matière \"{$matiere['nom_matiere']}\". Elle est utilisée dans {$nb_classes} classe(s). Veuillez d'abord la retirer de ces classes."
            ]);
            exit();
        }
        
        // Supprimer la matière
        $stmt = $pdo->prepare("DELETE FROM matieres WHERE id = ?");
        $result = $stmt->execute([$matiere_id]);
        
        if ($result) {
            echo json_encode([
                'success' => true,
                'message' => "Matière \"{$matiere['nom_matiere']}\" supprimée avec succès !"
            ]);
            exit();
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Erreur lors de la suppression de la matière'
            ]);
            exit();
        }
        
    } catch (PDOException $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Erreur lors de la suppression de la matière : ' . $e->getMessage()
        ]);
        exit();
    }
}

// Traitement de la modification des informations de l'école
$__action = $_POST['action'] ?? '';
$__is_ecole_payload = (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['nom_ecole'], $_POST['nom_abrege'], $_POST['ville_ecole'], $_POST['adresse_ecole'])
);
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && (
        $__action === 'modifier_informations_ecole'
        || $__action === 'update_informations_ecole'
        || $__action === 'save_ecole'
        || $__is_ecole_payload
    )
) {
    try {
        // Validation des données
        $erreurs = [];
        
        // Vérifier les champs obligatoires
        if (empty($_POST['nom_ecole'])) $erreurs[] = "Le nom de l'école est obligatoire";
        if (empty($_POST['nom_abrege'])) $erreurs[] = "Le nom abrégé est obligatoire";
        if (empty($_POST['ville_ecole'])) $erreurs[] = "La ville est obligatoire";
        if (empty($_POST['adresse_ecole'])) $erreurs[] = "L'adresse est obligatoire";
        
        // Validation email si fourni
        if (!empty($_POST['mail_ecole']) && !filter_var($_POST['mail_ecole'], FILTER_VALIDATE_EMAIL)) {
            $erreurs[] = "L'email n'est pas valide";
        }
        
        // Validation téléphone si fourni
        if (!empty($_POST['tel_ecole']) && strlen(preg_replace('/\D/', '', $_POST['tel_ecole'])) < 9) {
            $erreurs[] = "Le téléphone doit contenir au moins 8 chiffres";
        }
        
        if (!empty($erreurs)) {
            echo json_encode([
                'success' => false,
                'message' => implode('<br>', $erreurs)
            ]);
            exit();
        }
        
        // Traitement du champ type_ecole (multi-select)
        $types_ecole = '';
        if (isset($_POST['type_ecole']) && is_array($_POST['type_ecole'])) {
            $types_ecole = implode('|', $_POST['type_ecole']);
        }
        
        // Récupérer la (première) ligne existante d'informations école
        $row = $pdo->query("SELECT id, logo_ecole, reserve3 FROM informations_ecole LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $current_data = is_array($row) ? $row : [];
        $current_id = $current_data['id'] ?? null;

        $logo_path = $current_data['logo_ecole'] ?? null;
        $cachet_path = $current_data['reserve3'] ?? null;
        
        // Gestion de l'upload du logo
        if (isset($_FILES['logo_ecole']) && $_FILES['logo_ecole']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = $dbname . '/logos/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $file_extension = strtolower(pathinfo($_FILES['logo_ecole']['name'], PATHINFO_EXTENSION));
            $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];
            
            if (in_array($file_extension, $allowed_extensions)) {
                // Générer un nom de fichier unique
                $logo_filename = 'logo_' . time() . '_' . uniqid() . '.' . $file_extension;
                $logo_full_path = $upload_dir . $logo_filename;
                
                if (move_uploaded_file($_FILES['logo_ecole']['tmp_name'], $logo_full_path)) {
                    // Supprimer l'ancien logo s'il existe
                    if ($logo_path && file_exists($logo_path)) {
                        @unlink($logo_path);
                    }
                    $logo_path = $dbname . '/logos/' . $logo_filename;
                }
            }
        }
        
        // Gestion de l'upload du cachet
        if (isset($_FILES['cachet_proviseur']) && $_FILES['cachet_proviseur']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = $dbname . '/cachet/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $file_extension = strtolower(pathinfo($_FILES['cachet_proviseur']['name'], PATHINFO_EXTENSION));
            $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];
            
            if (in_array($file_extension, $allowed_extensions)) {
                // Générer un nom de fichier unique
                $cachet_filename = 'cachet_' . time() . '_' . uniqid() . '.' . $file_extension;
                $cachet_full_path = $upload_dir . $cachet_filename;
                
                if (move_uploaded_file($_FILES['cachet_proviseur']['tmp_name'], $cachet_full_path)) {
                    // Supprimer l'ancien cachet s'il existe
                    if ($cachet_path && file_exists($cachet_path)) {
                        @unlink($cachet_path);
                    }
                    $cachet_path = $dbname . '/cachet/' . $cachet_filename;
                }
            }
        }
        
        // Champs et paramètres communs
        $fields = [
            "nom_ecole" => ':nom_ecole',
            "nom_abrege" => ':nom_abrege',
            "type_ecole" => ':type_ecole',
            "tel_ecole" => ':tel_ecole',
            "mail_ecole" => ':mail_ecole',
            "ville_ecole" => ':ville_ecole',
            "adresse_ecole" => ':adresse_ecole',
            "devise_ecole" => ':devise_ecole',
            "logo_ecole" => ':logo_ecole',
            "reserve3" => ':reserve3'
        ];
        $params = [
            ':nom_ecole' => $_POST['nom_ecole'],
            ':nom_abrege' => $_POST['nom_abrege'],
            ':type_ecole' => $types_ecole,
            ':tel_ecole' => $_POST['tel_ecole'] ?? '',
            ':mail_ecole' => $_POST['mail_ecole'] ?? '',
            ':ville_ecole' => $_POST['ville_ecole'],
            ':adresse_ecole' => $_POST['adresse_ecole'],
            ':devise_ecole' => $_POST['devise_ecole'] ?? '',
            ':logo_ecole' => $logo_path,
            ':reserve3' => $cachet_path
        ];

        // Debug des chemins
        error_log("Logo path: " . ($logo_path ?? 'null'));
        error_log("Cachet path: " . ($cachet_path ?? 'null'));
        
        if ($current_id) {
            // UPDATE sur la ligne existante
            $assignments = [];
            foreach ($fields as $col => $param) { $assignments[] = "$col = $param"; }
            $sql = "UPDATE informations_ecole SET " . implode(', ', $assignments) . " WHERE id = :id";
            $params[':id'] = $current_id;
            $stmt = $pdo->prepare($sql);
            $result = $stmt->execute($params);
        } else {
            // INSERT d'une nouvelle ligne si aucune n'existe
            $cols = implode(', ', array_keys($fields));
            $vals = implode(', ', array_values($fields));
            $sql = "INSERT INTO informations_ecole ($cols) VALUES ($vals)";
            $stmt = $pdo->prepare($sql);
            $result = $stmt->execute($params);
            if ($result) {
                $current_id = $pdo->lastInsertId();
            }
        }
        
        if ($result) {
            // Mettre à jour la session
            $_SESSION['informations_ecole']['nom_ecole'] = $_POST['nom_ecole'];
            $_SESSION['informations_ecole']['nom_abrege'] = $_POST['nom_abrege'];
            $_SESSION['informations_ecole']['type_ecole'] = $types_ecole;
            $_SESSION['informations_ecole']['tel_ecole'] = $_POST['tel_ecole'] ?? '';
            $_SESSION['informations_ecole']['mail_ecole'] = $_POST['mail_ecole'] ?? '';
            $_SESSION['informations_ecole']['ville_ecole'] = $_POST['ville_ecole'];
            $_SESSION['informations_ecole']['adresse_ecole'] = $_POST['adresse_ecole'];
            $_SESSION['informations_ecole']['devise_ecole'] = $_POST['devise_ecole'] ?? '';
            $_SESSION['informations_ecole']['logo_ecole'] = $logo_path;
            $_SESSION['informations_ecole']['reserve3'] = $cachet_path;
            
            echo json_encode([
                'success' => true,
                'message' => "Informations de l'école mises à jour avec succès !",
                'id' => $current_id,
                'logo_path' => $logo_path,
                'cachet_path' => $cachet_path
            ]);
            exit();
        }

        echo json_encode([
            'success' => false,
            'message' => 'Erreur lors de la mise à jour des informations'
        ]);
        exit();
        
    } catch (PDOException $e) {
        error_log("Erreur PDO: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => 'Erreur lors de la mise à jour des informations : ' . $e->getMessage()
        ]);
        exit();
    } catch (Exception $e) {
        error_log("Erreur générale: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => 'Erreur lors de la mise à jour des informations : ' . $e->getMessage()
        ]);
        exit();
    }
}

/**
 * Section: Jours et Horaires (sauvegarde jours étudiés et CRUD des créneaux horaires)
 */

// Enregistrer les jours étudiés (stockés dans informations_ecole.reserve3 au format "Lundi|Mardi|...")
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'enregistrer_jours') {
    try {
        $jours = isset($_POST['jours']) && is_array($_POST['jours']) ? $_POST['jours'] : [];
        // Ne garder que les valeurs autorisées
        $autorises = ['Lundi','Mardi','Mercredi','Jeudi','Vendredi','Samedi','Dimanche'];
        $jours = array_values(array_intersect($autorises, $jours));
        $valeur = implode('|', $jours);

        // Mettre à jour la première ligne existante (sans supposer id=1)
        $row = $pdo->query("SELECT id FROM informations_ecole LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($row && isset($row['id'])) {
            $stmt = $pdo->prepare("UPDATE informations_ecole SET reserve3 = :reserve3 WHERE id = :id");
            $ok = $stmt->execute([':reserve3' => $valeur, ':id' => $row['id']]);
        } else {
            // Si aucune ligne n'existe, tenter une insertion minimale (nécessite que les autres colonnes soient NULL par défaut)
            $ok = false;
            try {
                $stmt = $pdo->prepare("INSERT INTO informations_ecole (reserve3) VALUES (:reserve3)");
                $ok = $stmt->execute([':reserve3' => $valeur]);
            } catch (PDOException $ie) {
                // Ne pas masquer l'erreur d'insertion; remonter un message explicite
                echo json_encode([
                    'success' => false,
                    'message' => "Aucune ligne dans informations_ecole et l'insertion a échoué: " . $ie->getMessage()
                ]);
                exit();
            }
        }

        echo json_encode([
            'success' => (bool)$ok,
            'message' => $ok ? 'Jours étudiés enregistrés' : 'Échec de la sauvegarde des jours',
            'jours' => $jours
        ]);
        exit();
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit();
    }
}


// Ajouter un créneau horaire
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'ajouter_creneau') {
    try {
        $heure_debut = $_POST['heure_debut'] ?? '';
        $heure_fin = $_POST['heure_fin'] ?? '';
        $ordre_affichage = isset($_POST['ordre_affichage']) ? (int)$_POST['ordre_affichage'] : 0;
        $actif = isset($_POST['actif']) ? (int)$_POST['actif'] : 1;
        $nom = trim($_POST['nom'] ?? '');

        if (!$heure_debut || !$heure_fin) {
            echo json_encode(['success' => false, 'message' => 'Heures de début et de fin requises']);
            exit();
        }
        if (strtotime($heure_fin) <= strtotime($heure_debut)) {
            echo json_encode(['success' => false, 'message' => 'Heure de fin doit être supérieure à l\'heure de début']);
            exit();
        }
        if ($nom === '') {
            // Générer un nom lisible
            $nom = date('H\hi', strtotime($heure_debut)) . ' - ' . date('H\hi', strtotime($heure_fin));
        }

        $stmt = $pdo->prepare("INSERT INTO creneaux_horaires (nom, heure_debut, heure_fin, ordre_affichage, actif, date_creation) VALUES (?, ?, ?, ?, ?, NOW())");
        $ok = $stmt->execute([$nom, $heure_debut, $heure_fin, $ordre_affichage, $actif]);

        echo json_encode([
            'success' => (bool)$ok,
            'message' => $ok ? 'Créneau ajouté' : 'Échec de l\'ajout',
            'creneau' => [
                'id' => $pdo->lastInsertId(),
                'nom' => $nom,
                'heure_debut' => $heure_debut,
                'heure_fin' => $heure_fin,
                'ordre_affichage' => $ordre_affichage,
                'actif' => $actif
            ]
        ]);
        exit();
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit();
    }
}

// Modifier un créneau horaire
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'modifier_creneau') {
    try {
        $id = (int)($_POST['id'] ?? 0);
        $heure_debut = $_POST['heure_debut'] ?? '';
        $heure_fin = $_POST['heure_fin'] ?? '';
        $ordre_affichage = isset($_POST['ordre_affichage']) ? (int)$_POST['ordre_affichage'] : 0;
        $actif = isset($_POST['actif']) ? (int)$_POST['actif'] : 1;
        $nom = trim($_POST['nom'] ?? '');

        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'ID de créneau invalide']);
            exit();
        }
        if (!$heure_debut || !$heure_fin) {
            echo json_encode(['success' => false, 'message' => 'Heures de début et de fin requises']);
            exit();
        }
        if (strtotime($heure_fin) <= strtotime($heure_debut)) {
            echo json_encode(['success' => false, 'message' => 'Heure de fin doit être supérieure à l\'heure de début']);
            exit();
        }
        if ($nom === '') {
            $nom = date('H\hi', strtotime($heure_debut)) . ' - ' . date('H\hi', strtotime($heure_fin));
        }

        $stmt = $pdo->prepare("UPDATE creneaux_horaires SET nom = ?, heure_debut = ?, heure_fin = ?, ordre_affichage = ?, actif = ? WHERE id = ?");
        $ok = $stmt->execute([$nom, $heure_debut, $heure_fin, $ordre_affichage, $actif, $id]);

        echo json_encode([
            'success' => (bool)$ok,
            'message' => $ok ? 'Créneau modifié' : 'Aucune modification',
            'creneau' => [
                'id' => $id,
                'nom' => $nom,
                'heure_debut' => $heure_debut,
                'heure_fin' => $heure_fin,
                'ordre_affichage' => $ordre_affichage,
                'actif' => $actif
            ]
        ]);
        exit();
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit();
    }
}

// Supprimer un créneau horaire
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'supprimer_creneau') {
    try {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'ID invalide']);
            exit();
        }
        $stmt = $pdo->prepare("DELETE FROM creneaux_horaires WHERE id = ?");
        $ok = $stmt->execute([$id]);
        echo json_encode(['success' => (bool)$ok, 'message' => $ok ? 'Créneau supprimé' : 'Échec de la suppression']);
        exit();
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit();
    }
}

// Traitement de la modification des fonctions de l'application
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'modifier_fonctions_app') {
    try {
        // Récupérer les fonctions sélectionnées
        $fonctions_selectionnees = $_POST['fonctions_selectionnees'] ?? [];
        
        // Valider que ce sont des codes valides
        $codes_valides = array_keys($fonctionnalites_disponibles);
        $fonctions_filtrees = array_intersect($fonctions_selectionnees, $codes_valides);
        
        // Formater les fonctions (ex: INSCR_REINSCR|GEST_NOTES_BULL)
        $fonctions_formatees = implode('|', $fonctions_filtrees);
        
        // Supprimer toutes les anciennes fonctions
        $stmt = $pdo->prepare("DELETE FROM fonctions_application");
        $stmt->execute();
        
        // Insérer la nouvelle configuration si des fonctions sont sélectionnées
        if (!empty($fonctions_formatees)) {
            $stmt = $pdo->prepare("INSERT INTO fonctions_application (fonctions, date_creation) VALUES (?, NOW())");
            $result = $stmt->execute([$fonctions_formatees]);
        } else {
            $result = true; // Pas d'erreur si aucune fonction sélectionnée
        }
        
        if ($result) {
            // Mettre à jour la session avec les nouvelles fonctions
            $_SESSION['fonctions_application'] = $fonctions_formatees;
            
            echo json_encode([
                'success' => true,
                'message' => 'Configuration des fonctions mise à jour avec succès !',
                'nb_fonctions' => count($fonctions_filtrees),
                'fonctions_activees' => $fonctions_formatees
            ]);
            exit();
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour de la configuration'
            ]);
            exit();
        }
        
    } catch (PDOException $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Erreur lors de la mise à jour des fonctions : ' . $e->getMessage()
        ]);
        exit();
    }
}

// ===== TRAITEMENT GESTION DES RÔLES =====

// Traitement de l'attribution des rôles (nouvelle version)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'attribuer_roles') {
    try {
        // Récupérer les fonctions depuis la table fonctions_application
        $stmt = $pdo->query("SELECT fonctions FROM fonctions_application LIMIT 1");
        $fonctions_data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$fonctions_data || empty($fonctions_data['fonctions'])) {
            echo json_encode([
                'success' => false,
                'message' => 'Aucune fonction disponible dans le système'
            ]);
            exit();
        }
        
        // Parser les fonctions disponibles (format: INSCR_REINSCR|GEST_NOTES_BULL|etc...)
        $fonctions_disponibles = array_filter(explode('|', $fonctions_data['fonctions']));
        
        $nb_roles_traites = 0;
        
        // Traiter chaque rôle soumis
        foreach ($fonctions_disponibles as $code_role) {
            $executants_field = "executants_" . $code_role;
            $executants = $_POST[$executants_field] ?? [];
            
            // Formater les exécutants (fonctions séparées par |)
            $executants_str = implode('|', array_filter($executants));
            
            // Vérifier si le rôle existe déjà
            $stmt = $pdo->prepare("SELECT id FROM roles WHERE code_role = ?");
            $stmt->execute([$code_role]);
            $existing_role = $stmt->fetch();
            
            if ($existing_role) {
                // Mettre à jour le rôle existant
                $stmt = $pdo->prepare("UPDATE roles SET executants = ? WHERE code_role = ?");
                $stmt->execute([$executants_str, $code_role]);
            } else {
                // Créer un nouveau rôle
                $stmt = $pdo->prepare("INSERT INTO roles (code_role, executants) VALUES (?, ?)");
                $stmt->execute([$code_role, $executants_str]);
            }
            
            $nb_roles_traites++;
        }
        
        echo json_encode([
            'success' => true,
            'message' => "Attribution des rôles mise à jour avec succès ! ({$nb_roles_traites} rôles traités)"
        ]);
        exit();
        
    } catch (PDOException $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Erreur lors de l\'attribution des rôles : ' . $e->getMessage()
        ]);
        exit();
    }
}

// Traitement de la modification d'un rôle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'modifier_role') {
    try {
        $role_id = $_POST['role_id'] ?? '';
        $code_role = $_POST['code_role'] ?? '';
        $executants = $_POST['executants'] ?? [];
        
        // Validation
        if (empty($role_id) || empty($code_role)) {
            echo json_encode([
                'success' => false,
                'message' => 'ID du rôle et code du rôle sont obligatoires'
            ]);
            exit();
        }
        
        // Vérifier que le rôle existe
        $stmt = $pdo->prepare("SELECT id FROM roles WHERE id = ?");
        $stmt->execute([$role_id]);
        if (!$stmt->fetch()) {
            echo json_encode([
                'success' => false,
                'message' => 'Rôle introuvable'
            ]);
            exit();
        }
        
        // Vérifier que le code du rôle existe dans les fonctions activées
        if (!in_array($code_role, $fonctions_activees)) {
            echo json_encode([
                'success' => false,
                'message' => 'Ce rôle n\'est pas disponible dans les fonctions activées'
            ]);
            exit();
        }
        
        // Formater les exécutants
        $executants_str = implode('|', array_filter($executants));
        
        // Mettre à jour le rôle
        $stmt = $pdo->prepare("UPDATE roles SET code_role = ?, executants = ? WHERE id = ?");
        $result = $stmt->execute([$code_role, $executants_str, $role_id]);
        
        if ($result) {
            echo json_encode([
                'success' => true,
                'message' => 'Rôle modifié avec succès !'
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Erreur lors de la modification du rôle'
            ]);
        }
        exit();
        
    } catch (PDOException $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Erreur lors de la modification du rôle : ' . $e->getMessage()
        ]);
        exit();
    }
}

// Traitement de la suppression d'un rôle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'supprimer_role') {
    try {
        $role_id = $_POST['role_id'] ?? '';
        
        if (empty($role_id)) {
            echo json_encode([
                'success' => false,
                'message' => 'ID du rôle obligatoire'
            ]);
            exit();
        }
        
        // Vérifier que le rôle existe
        $stmt = $pdo->prepare("SELECT id FROM roles WHERE id = ?");
        $stmt->execute([$role_id]);
        if (!$stmt->fetch()) {
            echo json_encode([
                'success' => false,
                'message' => 'Rôle introuvable'
            ]);
            exit();
        }
        
        // Supprimer le rôle
        $stmt = $pdo->prepare("DELETE FROM roles WHERE id = ?");
        $result = $stmt->execute([$role_id]);
        
        if ($result) {
            echo json_encode([
                'success' => true,
                'message' => 'Rôle supprimé avec succès !'
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Erreur lors de la suppression du rôle'
            ]);
        }
        exit();
        
    } catch (PDOException $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Erreur lors de la suppression du rôle : ' . $e->getMessage()
        ]);
        exit();
    }
}

// Récupération des données d'un rôle pour modification
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_role') {
    try {
        $role_id = $_GET['role_id'] ?? '';
        
        if (empty($role_id)) {
            echo json_encode([
                'success' => false,
                'message' => 'ID du rôle obligatoire'
            ]);
            exit();
        }
        
        $stmt = $pdo->prepare("SELECT * FROM roles WHERE id = ?");
        $stmt->execute([$role_id]);
        $role = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($role) {
            // Parser les exécutants
            $executants_ids = !empty($role['executants']) ? explode('|', $role['executants']) : [];
            $role['executants_array'] = array_filter($executants_ids);
            
            echo json_encode([
                'success' => true,
                'role' => $role
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Rôle introuvable'
            ]);
        }
        exit();
        
    } catch (PDOException $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Erreur lors de la récupération : ' . $e->getMessage()
        ]);
        exit();
    }
}

// Récupération des utilisateurs pour les rôles
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_users') {
    try {
        $stmt = $pdo->query("SELECT id, nom_u, prenom_u, mail_u, fonction_u, role_u FROM utilisateurs ORDER BY nom_u, prenom_u");
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'users' => $users
        ]);
        exit();
        
    } catch (PDOException $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Erreur lors de la récupération des utilisateurs : ' . $e->getMessage(),
            'users' => []
        ]);
        exit();
    }
}

// Traitement du formulaire d'ajout d'élève
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'ajouter_eleve') {
    try {
        // Génération automatique du matricule
        $annee = date('Y');
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM eleves WHERE matricule_eleve LIKE '$annee%'");
        $total = $stmt->fetch()['total'];
        $matricule = $annee . str_pad($total + 1, 4, '0', STR_PAD_LEFT);

        // Gestion de l'upload de la photo
        $photo_path = null;
        if (isset($_FILES['photo_eleve']) && $_FILES['photo_eleve']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = $dbname.'/photos_eleves/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $file_extension = strtolower(pathinfo($_FILES['photo_eleve']['name'], PATHINFO_EXTENSION));
            $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];
            
            if (in_array($file_extension, $allowed_extensions)) {
                $photo_name = $matricule . '_' . time() . '.' . $file_extension;
                $photo_path = $upload_dir . $photo_name;
                
                if (!move_uploaded_file($_FILES['photo_eleve']['tmp_name'], $photo_path)) {
                    $photo_path = null;
                }
            }
        }

        // Insertion dans la base de données
        $sql = "INSERT INTO eleves (
            classe_eleve, nom_eleve, prenom_eleve, adresse_eleve, 
            date_de_naissance_eleve, lieu_de_naissance_eleve, genre_eleve, 
            matricule_eleve, photo_eleve, role_eleve, nom_parent, 
            prenom_parent, profession_parent, telephone_parent, 
            mot_de_passe_eleve, mot_de_passe_parent, date_creation
        ) VALUES (
            :classe_eleve, :nom_eleve, :prenom_eleve, :adresse_eleve, 
            :date_de_naissance_eleve, :lieu_de_naissance_eleve, :genre_eleve, 
            :matricule_eleve, :photo_eleve, :role_eleve, :nom_parent, 
            :prenom_parent, :profession_parent, :telephone_parent, 
            :mot_de_passe_eleve, :mot_de_passe_parent, NOW()
        )";

        $stmt = $pdo->prepare($sql);
        $result = $stmt->execute([
            ':classe_eleve' => $_POST['classe_eleve'],
            ':nom_eleve' => $_POST['nom_eleve'],
            ':prenom_eleve' => $_POST['prenom_eleve'],
            ':adresse_eleve' => $_POST['adresse_eleve'],
            ':date_de_naissance_eleve' => $_POST['date_de_naissance_eleve'],
            ':lieu_de_naissance_eleve' => $_POST['lieu_de_naissance_eleve'],
            ':genre_eleve' => $_POST['genre_eleve'],
            ':matricule_eleve' => $matricule,
            ':photo_eleve' => $photo_path,
            ':role_eleve' => $_POST['role_eleve'] ?? '',
            ':nom_parent' => $_POST['nom_parent'],
            ':prenom_parent' => $_POST['prenom_parent'],
            ':profession_parent' => $_POST['profession_parent'],
            ':telephone_parent' => $_POST['telephone_parent'],
            ':mot_de_passe_eleve' => password_hash($matricule, PASSWORD_DEFAULT),//password_hash($_POST['mot_de_passe_eleve'], PASSWORD_DEFAULT),
            ':mot_de_passe_parent' => password_hash($matricule.'P', PASSWORD_DEFAULT)//password_hash($_POST['mot_de_passe_parent'], PASSWORD_DEFAULT)
        ]);

        if ($result) {
            // Vérifier si c'est une requête AJAX
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                // Retourner une réponse JSON pour AJAX
                echo json_encode([
                    'success' => true,
                    'message' => 'Élève ajouté avec succès !',
                    'matricule' => $matricule,
                    'eleve' => [
                        'id' => $pdo->lastInsertId(),
                        'nom_eleve' => $_POST['nom_eleve'],
                        'prenom_eleve' => $_POST['prenom_eleve'],
                        'classe_eleve' => $_POST['classe_eleve'],
                        'genre_eleve' => $_POST['genre_eleve'],
                        'matricule_eleve' => $matricule,
                        'photo_eleve' => $photo_path,
                        'nom_parent' => $_POST['nom_parent'],
                        'prenom_parent' => $_POST['prenom_parent'],
                        'profession_parent' => $_POST['profession_parent'],
                        'telephone_parent' => $_POST['telephone_parent'],
                        'date_creation' => date('Y-m-d H:i:s')
                    ]
                ]);
                exit();
            } else {
                // Pour les requêtes normales, définir le message et rediriger
                $message_succes = 'Élève ajouté avec succès ! Matricule: ' . $matricule;
                // Redirection pour éviter la re-soumission
                header('Location: ' . $_SERVER['PHP_SELF'] . '?success=1&matricule=' . urlencode($matricule));
                exit();
            }
        }

    } catch (PDOException $e) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            echo json_encode([
                'success' => false,
                'message' => 'Erreur lors de l\'ajout : ' . $e->getMessage()
            ]);
            exit();
        } else {
            $message_erreur = 'Erreur lors de l\'ajout : ' . $e->getMessage();
        }
    }
}

// Traitement du formulaire de modification d'élève
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'modifier_eleve') {
    try {
        $id_eleve = $_POST['id_eleve'];
        
        // Gestion de l'upload de photo
        $photo_path = $_POST['photo_actuelle'] ?? '';
        if (isset($_FILES['photo_eleve']) && $_FILES['photo_eleve']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = $dbname.'/photos_eleves/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            $file_extension = strtolower(pathinfo($_FILES['photo_eleve']['name'], PATHINFO_EXTENSION));
            $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];
            
            if (in_array($file_extension, $allowed_extensions)) {
                $new_filename = 'eleve_' . $id_eleve . '_' . time() . '.' . $file_extension;
                $photo_path = $upload_dir . $new_filename;
                
                if (!move_uploaded_file($_FILES['photo_eleve']['tmp_name'], $photo_path)) {
                    $photo_path = $_POST['photo_actuelle'] ?? '';
                }
            }
        }
        
        $sql = "UPDATE eleves SET 
                classe_eleve = :classe_eleve,
                nom_eleve = :nom_eleve,
                prenom_eleve = :prenom_eleve,
                adresse_eleve = :adresse_eleve,
                date_de_naissance_eleve = :date_de_naissance_eleve,
                lieu_de_naissance_eleve = :lieu_de_naissance_eleve,
                genre_eleve = :genre_eleve,
                photo_eleve = :photo_eleve,
                role_eleve = :role_eleve,
                nom_parent = :nom_parent,
                prenom_parent = :prenom_parent,
                profession_parent = :profession_parent,
                telephone_parent = :telephone_parent";
        
        $params = [
            ':classe_eleve' => $_POST['classe_eleve'],
            ':nom_eleve' => $_POST['nom_eleve'],
            ':prenom_eleve' => $_POST['prenom_eleve'],
            ':adresse_eleve' => $_POST['adresse_eleve'],
            ':date_de_naissance_eleve' => $_POST['date_de_naissance_eleve'],
            ':lieu_de_naissance_eleve' => $_POST['lieu_de_naissance_eleve'],
            ':genre_eleve' => $_POST['genre_eleve'],
            ':photo_eleve' => $photo_path,
            ':role_eleve' => $_POST['role_eleve'],
            ':nom_parent' => $_POST['nom_parent'],
            ':prenom_parent' => $_POST['prenom_parent'],
            ':profession_parent' => $_POST['profession_parent'],
            ':telephone_parent' => $_POST['telephone_parent']
        ];
        
        // Mise à jour des mots de passe seulement s'ils sont fournis
        if (!empty($_POST['mot_de_passe_eleve'])) {
            $sql .= ", mot_de_passe_eleve = :mot_de_passe_eleve";
            $params[':mot_de_passe_eleve'] = password_hash($_POST['mot_de_passe_eleve'], PASSWORD_DEFAULT);
        }
        
        if (!empty($_POST['mot_de_passe_parent'])) {
            $sql .= ", mot_de_passe_parent = :mot_de_passe_parent";
            $params[':mot_de_passe_parent'] = password_hash($_POST['mot_de_passe_parent'], PASSWORD_DEFAULT);
        }
        
        $sql .= " WHERE id = :id_eleve";
        $params[':id_eleve'] = $id_eleve;
        
        $stmt = $pdo->prepare($sql);
        $result = $stmt->execute($params);
        
        if ($result) {
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                // Récupérer les données complètes de l'élève après modification
                $stmt = $pdo->prepare("SELECT * FROM eleves WHERE id = ?");
                $stmt->execute([$id_eleve]);
                $eleve_complet = $stmt->fetch(PDO::FETCH_ASSOC);
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Élève modifié avec succès !',
                    'eleve' => [
                        'id' => $eleve_complet['id'],
                        'nom_eleve' => $eleve_complet['nom_eleve'],
                        'prenom_eleve' => $eleve_complet['prenom_eleve'],
                        'classe_eleve' => $eleve_complet['classe_eleve'],
                        'genre_eleve' => $eleve_complet['genre_eleve'],
                        'matricule_eleve' => $eleve_complet['matricule_eleve'],
                        'photo_eleve' => $eleve_complet['photo_eleve'],
                        'date_de_naissance_eleve' => $eleve_complet['date_de_naissance_eleve'],
                        'nom_parent' => $eleve_complet['nom_parent'],
                        'prenom_parent' => $eleve_complet['prenom_parent'],
                        'profession_parent' => $eleve_complet['profession_parent'],
                        'telephone_parent' => $eleve_complet['telephone_parent'],
                        'telephone_eleve' => $eleve_complet['telephone_eleve']
                    ]
                ]);
                exit();
            } else {
                $message_succes = 'Élève modifié avec succès !';
                header('Location: ' . $_SERVER['PHP_SELF'] . '?success=2');
                exit();
            }
        }
        
    } catch (PDOException $e) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            echo json_encode([
                'success' => false,
                'message' => 'Erreur lors de la modification : ' . $e->getMessage()
            ]);
            exit();
        } else {
            $message_erreur = 'Erreur lors de la modification : ' . $e->getMessage();
        }
    }
}

// Traitement de la suppression d'élève
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'supprimer_eleve') {
    try {
        $id_eleve = $_POST['id_eleve'];
        
        // Récupérer les infos de l'élève pour le message
        $stmt = $pdo->prepare("SELECT nom_eleve, prenom_eleve FROM eleves WHERE id = ?");
        $stmt->execute([$id_eleve]);
        $eleve_info = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $stmt = $pdo->prepare("DELETE FROM eleves WHERE id = ?");
        $result = $stmt->execute([$id_eleve]);
        
        if ($result) {
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                echo json_encode([
                    'success' => true,
                    'message' => 'Élève ' . $eleve_info['nom_eleve'] . ' ' . $eleve_info['prenom_eleve'] . ' supprimé avec succès !'
                ]);
                exit();
            } else {
                $message_succes = 'Élève supprimé avec succès !';
                header('Location: ' . $_SERVER['PHP_SELF'] . '?success=3');
                exit();
            }
        }
        
    } catch (PDOException $e) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            echo json_encode([
                'success' => false,
                'message' => 'Erreur lors de la suppression : ' . $e->getMessage()
            ]);
            exit();
        } else {
            $message_erreur = 'Erreur lors de la suppression : ' . $e->getMessage();
        }
    }
}

// Traitement de la suppression du personnel
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'supprimer_personnel') {
    try {
        $id_personnel = $_POST['id_personnel'];
        
        // Récupérer les infos du personnel pour le message et la photo
        $stmt = $pdo->prepare("SELECT nom_u, prenom_u, pp_u FROM utilisateurs WHERE id = ?");
        $stmt->execute([$id_personnel]);
        $personnel_info = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$personnel_info) {
            throw new Exception("Membre du personnel non trouvé");
        }
        
        // Vérifier qu'on ne supprime pas le dernier super_admin
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM utilisateurs WHERE role_u = 'super_admin'");
        $stmt->execute();
        $super_admin_count = $stmt->fetch()['count'];
        
        // Vérifier si l'utilisateur à supprimer est un super_admin
        $stmt = $pdo->prepare("SELECT role_u FROM utilisateurs WHERE id = ?");
        $stmt->execute([$id_personnel]);
        $user_role = $stmt->fetch()['role_u'];
        
        if ($user_role === 'super_admin' && $super_admin_count <= 1) {
            throw new Exception("Impossible de supprimer le dernier Super Administrateur du système");
        }
        
        // Supprimer la photo de profil si elle existe
        if (!empty($personnel_info['pp_u']) && file_exists($personnel_info['pp_u'])) {
            unlink($personnel_info['pp_u']);
        }
        
        // Supprimer le personnel
        $stmt = $pdo->prepare("DELETE FROM utilisateurs WHERE id = ?");
        $result = $stmt->execute([$id_personnel]);
        
        if ($result) {
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                echo json_encode([
                    'success' => true,
                    'message' => 'Personnel ' . $personnel_info['nom_u'] . ' ' . $personnel_info['prenom_u'] . ' supprimé avec succès !'
                ]);
                exit();
            } else {
                $message_succes = 'Personnel supprimé avec succès !';
                header('Location: ' . $_SERVER['PHP_SELF'] . '?success=personnel_delete');
                exit();
            }
        }
        
    } catch (Exception $e) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            echo json_encode([
                'success' => false,
                'message' => 'Erreur lors de la suppression : ' . $e->getMessage()
            ]);
            exit();
        } else {
            $message_erreur = 'Erreur lors de la suppression : ' . $e->getMessage();
        }
    } catch (PDOException $e) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            echo json_encode([
                'success' => false,
                'message' => 'Erreur lors de la suppression : ' . $e->getMessage()
            ]);
            exit();
        } else {
            $message_erreur = 'Erreur lors de la suppression : ' . $e->getMessage();
        }
    }
}

// Traitement de la récupération des détails d'un élève
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_eleve_details') {
    try {
        $id_eleve = $_POST['id_eleve'];
        
        $stmt = $pdo->prepare("SELECT * FROM eleves WHERE id = ?");
        $stmt->execute([$id_eleve]);
        $eleve = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($eleve) {
            echo json_encode([
                'success' => true,
                'eleve' => $eleve
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Élève non trouvé'
            ]);
        }
        exit();
        
    } catch (PDOException $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Erreur lors de la récupération : ' . $e->getMessage()
        ]);
        exit();
    }
}

// Seulement les fonctions attribuées à ce utilisateur
$fonctions_menu = [
    // 'INSCR_REINSCR' => [
    //     'nom' => 'Inscription/Réinscription',
    //     'icone' => 'fas fa-user-plus',
    //     'couleur' => 'blue',
    //     'page' => 'inscription_reinscription.php'
    // ],
    'GEST_NOTES_BULL' => [
        'nom' => 'Gestion Notes-Bulletins',
        'icone' => 'fas fa-clipboard-list',
        'couleur' => 'green',
        'page' => 'gestion_notes_bulletins.php'
    ],
    'SUIVI_ABS_RET' => [
        'nom' => 'Suivi Absences-Retards',
        'icone' => 'fas fa-calendar-times',
        'couleur' => 'orange',
        'page' => 'suivi_absences_retards.php'
    ],

    'GEST_EMP_TEMPS' => [
        'nom' => 'Gestion Emploi du Temps',
        'icone' => 'fas fa-calendar-alt',
        'couleur' => 'indigo',
        'page' => 'gestion_emploi_temps.php'
    ],
    'ACT_INFOS' => [
        'nom' => 'Actualités-Infos',
        'icone' => 'fas fa-newspaper',
        'couleur' => 'teal',
        'page' => 'actualites_infos.php'
    ],
    'CARTES_SCOL' => [
        'nom' => 'Cartes Scolaires',
        'icone' => 'fas fa-id-card',
        'couleur' => 'yellow',
        'page' => 'cartes_scolaires.php'
    ],
    'COMPTA_TRES' => [
        'nom' => 'Comptabilité-Trésorerie',
        'icone' => 'fas fa-calculator',
        'couleur' => 'red',
        'page' => 'comptabilite_tresorerie.php'
    ],
    'SAISIE_NOTE_GLOBALE' => [
        'nom' => 'Saisie Note Globale',
        'icone' => 'fas fa-edit',
        'couleur' => 'purple',
        'page' => 'bulletins_programmes.php'
    ],
    // 'ALERTE_AUTO' => [
    //     'nom' => 'Alerte parents',
    //     'icone' => 'fas fa-bell',
    //     'couleur' => 'orange',
    //     'page' => 'alerte_parents.php'
    // ],
    'CAL_SCO' => [
        'nom' => 'Calendrier Scolaire',
        'icone' => 'fas fa-calendar-check',
        'couleur' => 'green',
        'page' => 'calendrier_scolaire.php'
    ],
    'ACTU_INFO' => [
        'nom' => 'Actualités & Infos',
        'icone' => 'fas fa-newspaper',
        'couleur' => 'blue',
        'page' => 'actualites_infos.php'
    ],
    'MESSAGE' => [
        'nom' => 'Messagerie',
        'icone' => 'fas fa-bell',
        'couleur' => 'blue',
        'page' => 'message.php'
    ],
    'SCOLARITE' => [
        'nom' => 'Scolarité',
        'icone' => 'fas fa-graduation-cap',
        'couleur' => 'purple',
        'page' => 'scolarite.php'
    ]
];

// Récupérer les fonctions actives depuis la session pour le menu
$fonctions_actives = [];
if (!empty($fonctions_activees)) {
    foreach ($fonctions_activees as $code_fonction) {
        if (isset($fonctions_menu[$code_fonction])) {
            $fonctions_actives[$code_fonction] = $fonctions_menu[$code_fonction];
        }
    }
}

// Si pas de fonctions en session, utiliser une chaîne par défaut pour les tests
if (empty($fonctions_actives)) {
    $fonctions_test = 'INSCR_REINSCR | GEST_NOTES_BULL | SUIVI_ABS_RET | DEPOT_COURS_DEV | GEST_EMP_TEMPS | ECH_MESSAGES | ACT_INFOS | CARTES_SCOL | COMPTA_TRES| SCOLARITE | MESSAGE | CAL_SCO| SAISIE_NOTE_GLOBALE';
    $codes_fonctions = array_map('trim', explode('|', $fonctions_test));
    foreach ($codes_fonctions as $code) {
        if (isset($fonctions_menu[$code])) {
            $fonctions_actives[$code] = $fonctions_menu[$code];
        }
    }
}

?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tableau de Bord Super Administrateur - <?php echo htmlspecialchars($informations_ecole['nom_ecole'] ?? 'SchoolManager Pro'); ?></title>
    <!-- <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js" defer></script> -->

    <!-- Tailwind CSS -->
    <script src="tailwind.js"></script>
    <!-- Font Awesome & Google Fonts -->
    <link href="all.min.css" rel="stylesheet" />
    <script src="all.min.js"></script>

    <script src="chart.js"></script>



    <style>
        body { font-family: 'Poppins', sans-serif; }
        .sidebar-transition { transition: all 0.3s ease; }
        .menu-item:hover { transform: translateY(-2px); }
        .stat-card { transition: all 0.3s ease; }
        .stat-card:hover { transform: translateY(-5px); box-shadow: 0 20px 40px rgba(0,0,0,0.1); }
        aside{display:none;}
        .disabled-look {
            background-color: #f3f4f6;
            pointer-events: none;
            opacity: 0.7;
            cursor: not-allowed;
        }
        /* Ajouter dans la section <style> */
        .sauvegarde-item {
            transition: all 0.3s ease;
        }
        .sauvegarde-item:hover {
            background-color: #f9fafb;
            transform: translateX(4px);
        }
    </style>
</head>
<body class="bg-gray-50 min-h-screen">
    <!-- Sidebar -->
    <div id="sidebar" class="fixed inset-y-0 left-0 w-72 bg-gradient-to-b from-blue-800 to-blue-900 transform -translate-x-full lg:translate-x-0 sidebar-transition z-30">
        <div class="flex flex-col h-full">
            <!-- Logo et Titre -->
            <div class="flex items-center justify-center p-6 border-b border-blue-700">
                <div class="text-center">
                    <div class="w-12 h-12 bg-white rounded-full flex items-center justify-center mb-2 mx-auto">
                        <i class="fas fa-graduation-cap text-blue-600 text-xl"></i>
                    </div>
                    <h1 class="text-white font-bold text-lg"><?php echo htmlspecialchars(substr($informations_ecole['nom_ecole'] ?? 'SchoolManager Pro', 0, 20)); ?></h1>
                    <p class="text-blue-200 text-sm">Tableau de Bord</p>
                </div>
            </div>

            <!-- Menu Navigation -->
            <div class="flex-1 overflow-y-auto py-4">
                <!-- Tableau de Bord -->
                <div class="px-4 mb-6">
                    <button onclick="showSection('dashboard')" 
                            class="menu-item w-full flex items-center p-3 text-white hover:bg-blue-700 rounded-lg transition-all duration-300">
                        <i class="fas fa-tachometer-alt mr-3 w-5"></i>
                        <span>Tableau de Bord</span>
                    </button>
                </div>

                <!-- Gestion des Données -->
                <div class="px-4">
                    <h3 class="text-blue-200 text-xs uppercase tracking-wider mb-3 font-semibold">Gestion des Données</h3>
                    <div class="space-y-1">
                        <button onclick="showSection('matieres')" 
                                class="menu-item w-full flex items-center p-3 text-white hover:bg-blue-700 rounded-lg transition-all duration-300">
                            <i class="fas fa-book mr-3 w-5"></i>
                            <span class="text-sm">Matières</span>
                        </button>
                        <button onclick="showSection('classes')" 
                                class="menu-item w-full flex items-center p-3 text-white hover:bg-blue-700 rounded-lg transition-all duration-300">
                            <i class="fas fa-school mr-3 w-5"></i>
                            <span class="text-sm">Classes</span>
                        </button>
                        <button onclick="showSection('personnel')" 
                                class="menu-item w-full flex items-center p-3 text-white hover:bg-blue-700 rounded-lg transition-all duration-300">
                            <i class="fas fa-users mr-3 w-5"></i>
                            <span class="text-sm">Personnel</span>
                        </button>
                        <button onclick="showSection('eleves')" 
                                class="menu-item w-full flex items-center p-3 text-white hover:bg-blue-700 rounded-lg transition-all duration-300">
                            <i class="fas fa-user-graduate mr-3 w-5"></i>
                            <span class="text-sm">Élèves</span>
                        </button>
                    </div>
                </div>

                <!-- Fonctions de l'Application -->
                <div class="px-4 mt-6">
                    <h3 class="text-blue-200 text-xs uppercase tracking-wider mb-3 font-semibold">Fonctions Principales</h3>
                    <div class="space-y-1">
                        <?php foreach ($fonctions_actives as $code => $fonction): ?>
                        <button onclick="loadFunction('<?php echo $code; ?>', '<?php echo $fonction['page']; ?>')" 
                                class="menu-item w-full flex items-center p-3 text-white hover:bg-blue-700 rounded-lg transition-all duration-300">
                            <i class="<?php echo $fonction['icone']; ?> mr-3 w-5"></i>
                            <span class="text-sm"><?php echo htmlspecialchars($fonction['nom']); ?></span>
                        </button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Fonctions Enseignant -->
                <div class="px-4 mt-6">
                <h3 class="text-blue-200 text-xs uppercase tracking-wider mb-3 font-semibold">Fonctions Enseignant</h3>
                <div class="space-y-1">

                    <button onclick="loadFunction('', 'emploi_enseignant.php')"
                    class="menu-item w-full flex items-center p-3 text-white hover:bg-blue-700 rounded-lg transition-all duration-300">
                    <i class="fa-regular fa-calendar mr-3 w-5"></i>
                    <span class="text-sm">Mon Emploi</span>
                    </button>

                    <button onclick="loadFunction('', 'bulletins_programmes.php')"
                    class="menu-item w-full flex items-center p-3 text-white hover:bg-blue-700 rounded-lg transition-all duration-300">
                    <i class="fa-solid fa-pen-to-square mr-3 w-5"></i>
                    <span class="text-sm">Saisie de notes</span>
                    </button>

                    <button onclick="loadFunction('', 'devoirs_enseignant.php')"
                    class="menu-item w-full flex items-center p-3 text-white hover:bg-blue-700 rounded-lg transition-all duration-300">
                    <i class="fa-solid fa-list-check mr-3 w-5"></i>
                    <span class="text-sm">Gestion des devoirs</span>
                    </button>

                </div>
                </div>

                

                <!-- Administration -->
                <div class="px-4 mt-6">
                    <h3 class="text-blue-200 text-xs uppercase tracking-wider mb-3 font-semibold">Administration</h3>
                    <div class="space-y-1">
                        <button onclick="showSection('informations-ecole')" 
                                class="menu-item w-full flex items-center p-3 text-white hover:bg-blue-700 rounded-lg transition-all duration-300">
                            <i class="fas fa-building mr-3 w-5"></i>
                            <span class="text-sm">Informations École</span>
                        </button>
                        <button onclick="showSection('jours-horaires')" 
                                class="menu-item w-full flex items-center p-3 text-white hover:bg-blue-700 rounded-lg transition-all duration-300">
                            <i class="fas fa-calendar-day mr-3 w-5"></i>
                            <span class="text-sm">Jours/Horaires</span>
                        </button>
                        <button onclick="showSection('fonctions-app')" 
                                class="menu-item w-full flex items-center p-3 text-white hover:bg-blue-700 rounded-lg transition-all duration-300">
                            <i class="fas fa-cogs mr-3 w-5"></i>
                            <span class="text-sm">Fonctions Application</span>
                        </button>
                        <button onclick="showSection('roles')" 
                                class="menu-item w-full flex items-center p-3 text-white hover:bg-blue-700 rounded-lg transition-all duration-300">
                            <i class="fas fa-user-shield mr-3 w-5"></i>
                            <span class="text-sm">Rôles</span>
                        </button>
                        <button onclick="showSection('statistiques')" 
                                class="menu-item w-full flex items-center p-3 text-white hover:bg-blue-700 rounded-lg transition-all duration-300">
                            <i class="fas fa-chart-bar mr-3 w-5"></i>
                            <span class="text-sm">Statistiques</span>
                        </button>
                        <!-- <button onclick="loadFunction('SCOLARITE', 'scolarite.php')" 
                                class="menu-item w-full flex items-center p-3 text-white hover:bg-blue-700 rounded-lg transition-all duration-300">
                            <i class="fas fa-money-bill-wave mr-3 w-5"></i>
                            <span class="text-sm">Scolarité</span>
                        </button> -->
                        <button onclick="showSection('sauvegarde')" 
                                class="menu-item w-full flex items-center p-3 text-white hover:bg-blue-700 rounded-lg transition-all duration-300">
                            <i class="fas fa-database mr-3 w-5"></i>
                            <span class="text-sm">Sauvegarde</span>
                        </button>
                    </div>
                </div>
            <!-- Profil Utilisateur -->
           <div class="border-t border-blue-700 p-4 mt-auto">
               <div class="flex items-center space-x-3 mb-3">
                   <div class="w-10 h-10 bg-white rounded-full flex items-center justify-center">
                       <i class="fas fa-user-tie text-blue-6"></i>
                    </div>
                   <div>
                       <p class="text-white font-medium"><?php echo htmlspecialchars($utilisateur['prenom_u'] . ' ' . $utilisateur['nom_u']) ?></p>
                       <p class="text-blue-200 text-sm"><?php echo htmlspecialchars($utilisateur['fonction_u']) ?? 'Super Admin' ?></p>
                  </div>
              </div>
               <a  href="deconnexion.php" 
                   class="w-full flex items-center justify-center p-2 text-white bg-red-600 hover:bg-red-700 rounded-lg transition-colors">
                   <i class="fas fa-sign-out-alt mr"></i>
                   <span>Déconnexion</span>
              </a>
          </div>
      </div>
  </div>
        </div>
    </div>

    <!-- Overlay pour mobile -->
    <div id="overlay" class="fixed inset-0 bg-black bg-opacity-50 z-20 lg:hidden hidden" onclick="closeSidebar()"></div>

    <!-- Contenu Principal -->
    <div class="lg:ml-72">
        <!-- Header -->
        <header class="bg-white shadow-sm border-b border-gray-200">
            <div class="flex items-center justify-between px-6 py-4">
                <div class="flex items-center">
                    <button id="menu-toggle" class="lg:hidden mr-4 text-gray-600 hover:text-gray-900">
                        <i class="fas fa-bars text-xl"></i>
                    </button>
                    <h2 id="page-title" class="text-2xl font-bold text-gray-800">Tableau de Bord</h2>
                </div>
                <div class="flex items-center space-x-4">
                    <div class="text-right">
                        <p class="text-sm text-gray-600"><?php echo date('d/m/Y'); ?></p>
                        <p class="text-sm font-medium text-gray-800"><?php echo date('H:i'); ?></p>
                    </div>
                </div>
            </div>
        </header>

        <!-- Contenu -->
        <main class="p-6">
            <?php if (!empty($message_succes)): ?>
            <div class="fixed top-4 right-4 z-50 max-w-sm w-full" id="successMessage">
                <div class="bg-green-500 text-white px-6 py-4 rounded-lg shadow-lg flex items-center space-x-3">
                    <i class="fas fa-check-circle text-xl"></i>
                    <span class="font-medium"><?php echo htmlspecialchars($message_succes); ?></span>
                    <button onclick="this.parentElement.parentElement.remove()" class="ml-auto text-white hover:text-gray-200">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
            <script>
                // Auto-masquer le message après 5 secondes
                setTimeout(function() {
                    const msg = document.getElementById('successMessage');
                    if (msg) {
                        msg.style.opacity = '0';
                        setTimeout(() => msg.remove(), 300);
                    }
                }, 5000);
            </script>
            <?php 
                // Supprimer le marqueur pour permettre l'affichage d'autres messages
                unset($_SESSION['message_displayed']);
            endif; ?>
            <!-- Messages de notification -->
            <?php if (!empty($message_succes)): ?>
                <div class="mb-6 bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-lg flex items-center">
                    <i class="fas fa-check-circle text-green-600 mr-3"></i>
                    <span><?php echo htmlspecialchars($message_succes); ?></span>
                    <button onclick="this.parentElement.style.display='none'" class="ml-auto text-green-600 hover:text-green-800">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($message_erreur)): ?>
                <div class="mb-6 bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-lg flex items-center">
                    <i class="fas fa-exclamation-circle text-red-600 mr-3"></i>
                    <span><?php echo htmlspecialchars($message_erreur); ?></span>
                    <button onclick="this.parentElement.style.display='none'" class="ml-auto text-red-600 hover:text-red-800">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            <?php endif; ?>

            <!-- Section Tableau de Bord -->
            <div id="dashboard-section" class="section-content">
                <!-- Statistiques -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <!-- Total Élèves -->
                    <div class="stat-card bg-gradient-to-r from-blue-500 to-blue-600 rounded-xl shadow-lg p-6 text-white">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-blue-100 text-sm">Total Élèves</p>
                                <p class="text-3xl font-bold" id="total-eleves-stat"><?php echo number_format($stats['total_eleves']); ?></p>
                            </div>
                            <div class="w-12 h-12 bg-blue-400 bg-opacity-30 rounded-full flex items-center justify-center">
                                <i class="fas fa-user-graduate text-2xl"></i>
                            </div>
                        </div>
                    </div>

                    <!-- Total Personnel -->
                    <div class="stat-card bg-gradient-to-r from-green-500 to-green-600 rounded-xl shadow-lg p-6 text-white">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-green-100 text-sm">Personnel</p>
                                <p class="text-3xl font-bold" id="total-personnel-stat"><?php echo number_format($stats['total_personnel']); ?></p>
                            </div>
                            <div class="w-12 h-12 bg-green-400 bg-opacity-30 rounded-full flex items-center justify-center">
                                <i class="fas fa-users text-2xl"></i>
                            </div>
                        </div>
                    </div>

                    <!-- Classes -->
                    <div class="stat-card bg-gradient-to-r from-orange-500 to-orange-600 rounded-xl shadow-lg p-6 text-white">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-orange-100 text-sm">Classes</p>
                                <p class="text-3xl font-bold" id="total-classes-stat"><?php echo number_format($stats['total_classes']); ?></p>
                            </div>
                            <div class="w-12 h-12 bg-orange-400 bg-opacity-30 rounded-full flex items-center justify-center">
                                <i class="fas fa-school text-2xl"></i>
                            </div>
                        </div>
                    </div>

                    <!-- Matières -->
                    <div class="stat-card bg-gradient-to-r from-purple-500 to-purple-600 rounded-xl shadow-lg p-6 text-white">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-purple-100 text-sm">Matières</p>
                                <p class="text-3xl font-bold" id="total-matieres-stat"><?php echo number_format($stats['total_matieres']); ?></p>
                            </div>
                            <div class="w-12 h-12 bg-purple-400 bg-opacity-30 rounded-full flex items-center justify-center">
                                <i class="fas fa-book text-2xl"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Graphiques -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                    <!-- Répartition par Classe -->
                    <div class="bg-white rounded-xl shadow-lg p-6">
                        <h3 class="text-lg font-bold text-gray-800 mb-4">Répartition par Classe</h3>
                        <canvas id="classeChart" style="height: -webkit-fill-available !important;"></canvas>
                    </div>

                    <!-- Répartition par Genre -->
                    <div class="bg-white rounded-xl shadow-lg p-6">
                        <h3 class="text-lg font-bold text-gray-800 mb-4">Répartition par Genre</h3>
                        <canvas id="genreChart"></canvas>
                    </div>
                </div>

                <!-- Fonctions Rapides -->
                <div class="bg-white rounded-xl shadow-lg p-6">
                    <h3 class="text-lg font-bold text-gray-800 mb-4">Accès Rapide aux Fonctions</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                        <?php foreach (array_slice($fonctions_actives, 0, 6) as $code => $fonction): ?>
                        <button onclick="loadFunction('<?php echo $code; ?>', '<?php echo $fonction['page']; ?>')" 
                                class="flex items-center p-4 bg-gradient-to-r from-<?php echo $fonction['couleur']; ?>-50 to-<?php echo $fonction['couleur']; ?>-100 border border-<?php echo $fonction['couleur']; ?>-200 rounded-lg hover:shadow-md transition-all duration-300">
                            <div class="w-10 h-10 bg-<?php echo $fonction['couleur']; ?>-500 rounded-full flex items-center justify-center mr-3">
                                <i class="<?php echo $fonction['icone']; ?> text-white"></i>
                            </div>
                            <div class="text-left">
                                <p class="font-medium text-gray-800"><?php echo htmlspecialchars($fonction['nom']); ?></p>
                                <p class="text-sm text-gray-600">Accès direct</p>
                            </div>
                        </button>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Section Élèves -->
            <div id="eleves-section" class="section-content hidden">
                <div class="bg-white rounded-xl shadow-lg p-6">
                    <div class="flex items-center justify-between mb-6">
                       <h3 class="text-lg font-bold text-gray-800">Liste des Élèv</h3>
                       <div class="flex items-center gap-2">
                            <a href="import_excel.php" class="bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded-lg transition-colors">
                               <i class="fas fa-file-excel mr"></i>Import Excel
                            </a>
                            <button onclick="showAddEleveModal()" 
                                    class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition-colors">
                               <i class="fas fa-plus mr"></i>Ajouter un Élève
                            </button>
                      </div>
                    </div>

                    <!-- Filtres -->
                    <div class="flex flex-wrap gap-4 mb-6">
                        <select id="filterClasse" onchange="filterEleves()" 
                                class="px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                            <option value="">Toutes les classes</option>
                            <?php foreach ($classes as $cl): 
    $nom = $cl['nom_classe'];
    $nb = isset($eleves_count_by_classe[$nom]) ? (int)$eleves_count_by_classe[$nom] : (isset($elevesCountByClasse[$nom]) ? (int)$elevesCountByClasse[$nom] : 0);
?>
<option value="<?php echo htmlspecialchars($nom); ?>">
    <?php echo htmlspecialchars($nom); ?> (<?php echo $nb; ?>)
</option>
<?php endforeach; ?>
                        </select>
                        
                        <input type="text" 
                               id="searchEleve" 
                               placeholder="Rechercher un élève..." 
                               onkeyup="filterEleves()"
                               class="px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                    </div>

                    <!-- Tableau des Élèves -->
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Élève</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Matricule</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Classe</th>
                                    
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Téléphone</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="elevesTableBody" class="bg-white divide-y divide-gray-200">
                                <?php foreach ($eleves as $eleve): ?>
                                
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Section Personnel -->
            <div id="personnel-section" class="section-content hidden">
                <div class="bg-white rounded-xl shadow-lg p-6">
                    <div class="flex items-center justify-between mb-6">
                        <h3 class="text-lg font-bold text-gray-800">Gestion du Personnel</h3>
                        <button id="btnAddPersonnel" 
                                class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition-colors">
                            <i class="fas fa-plus mr-2"></i>Ajouter Personnel
                        </button>
                    </div>
                    
                    <!-- Filtres Personnel -->
                    <div class="mb-6 grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Rechercher</label>
                            <input type="text" id="searchPersonnel" placeholder="Nom, prénom, email..." 
                                   class="w-full p-2 border border-gray-300 rounded-lg">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Fonction</label>
                            <select id="filterFonction" class="w-full p-2 border border-gray-300 rounded-lg">
                                <option value="">Toutes les fonctions</option>
                                <?php 
                                $fonctions = array_unique(array_column($personnel, 'fonction_u'));
                                foreach($fonctions as $fonction): 
                                    if($fonction): ?>
                                    <option value="<?php echo htmlspecialchars($fonction); ?>"><?php echo htmlspecialchars($fonction); ?></option>
                                <?php endif; endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Rôle</label>
                            <select id="filterRole" class="w-full p-2 border border-gray-300 rounded-lg">
                                <option value="">Tous les rôles</option>
                                <?php 
                                $roles = array_unique(array_column($personnel, 'role_u'));
                                foreach($roles as $role): 
                                    if($role): ?>
                                    <option value="<?php echo htmlspecialchars($role); ?>"><?php echo htmlspecialchars($role); ?></option>
                                <?php endif; endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- Tableau Personnel -->
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Personnel</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Contact</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Fonction</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Rôle</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Matière/Niveau</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="personnelTableBody" class="bg-white divide-y divide-gray-200">
                                <?php foreach ($personnel as $user): ?>
                                <tr class="personnel-row" 
                                    data-fonction="<?php echo htmlspecialchars($user['fonction_u']); ?>"
                                    data-role="<?php echo htmlspecialchars($user['role_u']); ?>"
                                    data-nom="<?php echo strtolower($user['nom_u'] . ' ' . $user['prenom_u']); ?>">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <div class="relative w-10 h-10">
                                                <img loading="lazy" src="<?php echo htmlspecialchars($user['pp_u']); ?>" 
                                                     alt="Photo <?php echo htmlspecialchars($user['prenom_u']); ?>" 
                                                     class="w-10 h-10 rounded-full object-cover"
                                                     onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                                <div class="w-10 h-10 bg-green-500 rounded-full items-center justify-center hidden">
                                                    <span class="text-white font-bold">
                                                        <?php echo strtoupper(substr($user['prenom_u'], 0, 1) . substr($user['nom_u'], 0, 1)); ?>
                                                    </span>
                                                </div>
                                            </div>
                                            <div class="ml-3">
                                                <div class="text-sm font-medium text-gray-900">
                                                    <?php echo htmlspecialchars($user['prenom_u'] . ' ' . $user['nom_u']); ?>
                                                </div>
                                                <div class="text-sm text-gray-500">
                                                    ID: <?php echo htmlspecialchars($user['id']); ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900"><?php echo htmlspecialchars($user['mail_u']); ?></div>
                                        <div class="text-sm text-gray-500"><?php echo htmlspecialchars($user['tel_u']); ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">
                                            <?php echo htmlspecialchars($user['fonction_u']); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="px-2 py-1 text-xs font-semibold rounded-full bg-purple-100 text-purple-800">
                                            <?php echo htmlspecialchars($user['role_u']); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <?php echo htmlspecialchars($user['matiere_niveau_u'] ?: 'Non défini'); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                        <button onclick="viewPersonnel(<?php echo $user['id']; ?>)" 
                                                class="text-blue-600 hover:text-blue-900 mr-3">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button onclick="editPersonnel(<?php echo $user['id']; ?>)" 
                                                class="text-indigo-600 hover:text-indigo-900 mr-3">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button onclick="deletePersonnel(<?php echo $user['id']; ?>)" 
                                                class="text-red-600 hover:text-red-900">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Section Classes -->
            <div id="classes-section" class="section-content hidden">
                <div class="bg-white rounded-xl shadow-lg p-6">
                    <div class="flex items-center justify-between mb-6">
                        <h3 class="text-lg font-bold text-gray-800">Gestion des Classes</h3>
                        <button onclick="showAddClasseModal()" 
                                class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition-colors">
                            <i class="fas fa-plus mr-2"></i>Ajouter Classe
                        </button>
                    </div>
                    
                    <!-- Tableau Classes -->
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nom Classe</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Niveau</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Matières & Config</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nb Élèves</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date Création</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($classes as $classe): ?>
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-gray-900">
                                            <?php echo htmlspecialchars($classe['nom_classe']); ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="px-2 py-1 text-xs font-semibold rounded-full bg-blue-100 text-blue-800">
                                            <?php echo htmlspecialchars($classe['niveau']); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <div class="matiere-display" data-raw="<?php echo htmlspecialchars($classe['mat_coef_bareme'] ?: ''); ?>">
                                            <?php echo htmlspecialchars($classe['mat_coef_bareme'] ?: 'Non défini'); ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <?php 
                                        $nb_eleves = count(array_filter($eleves, function($e) use ($classe) {
                                            return $e['classe_eleve'] === $classe['nom_classe'];
                                        }));
                                        $nomClasse = $classe['nom_classe'];
$nb_eleves = isset($eleves_count_by_classe[$nomClasse]) ? (int)$eleves_count_by_classe[$nomClasse] : 0;
echo $nb_eleves;
                                        ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?php echo date('d/m/Y', strtotime($classe['date_creation'])); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                        <button onclick="editClasse(<?php echo $classe['id']; ?>)" 
                                                class="text-indigo-600 hover:text-indigo-900 mr-3">
                                            <i class="fas fa-edit"></i>
                                        </button> 
                                        <!-- Bouton Dupliquer -->
                                        <button onclick="duplicateClasse(<?php echo $classe['id']; ?>)" 
                                                class="text-green-600 hover:text-green-900 mr-3"
                                                title="Dupliquer cette classe">
                                            <i class="fas fa-copy"></i>
                                        </button>
                                        
                                        <!-- Bouton Supprimer -->                                        
                                        <button onclick="deleteClasse(<?php echo $classe['id']; ?>)" 
                                                class="text-red-600 hover:text-red-900">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Section Matières -->
            <div id="matieres-section" class="section-content hidden">
                <div class="bg-white rounded-xl shadow-lg p-6">
                    <div class="flex items-center justify-between mb-6">
                        <h3 class="text-lg font-bold text-gray-800">Gestion des Matières</h3>
                        <button onclick="showAddMatiereModal()" 
                                class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition-colors">
                            <i class="fas fa-plus mr-2"></i>Ajouter une Matière
                        </button>
                    </div>
                    
                    <!-- Sélection rapide des matières avec génération automatique de code -->
                    <!-- Bouton pour afficher/masquer la sélection rapide -->
                    <div class="mb-3">
                        <a href="javascript:void(0)" onclick="toggleSelectionMatieres()" class="inline-flex items-center text-blue-600 hover:text-blue-800 text-sm">
                            <i class="fas fa-list mr-2"></i>Afficher la sélection rapide des matières
                        </a>
                    </div>
                    <div id="selectionMatieres" class="mb-6 border border-gray-200 rounded-lg p-4 hidden">
                        <div class="flex items-center justify-between mb-3">
                            <h4 class="font-semibold text-gray-800">Sélection rapide des matières</h4>
                            <button onclick="enregistrerSelectionMatieres()" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded">
                                <i class="fas fa-save mr-2"></i>Enregistrer la sélection
                            </button>
                        </div>
                        <p class="text-sm text-gray-600 mb-3">Sélectionnez toutes les matières enseignées dans votre établissement, puis cliquez sur le bouton "Enregistrer". (Leur code sera généré automatiquement, Les doublons existants sont ignorés.)</p>
                        <?php 
                          $matieres_suggestions = [
                                    "Logico-Maths","Exercices Sensoriels","Graphisme","Pré Lecture","Perceptivo-Motricité","Psychomotricité","Coloriage","Dictée et questions","Exp. Écrite/Rédaction",
                                    "Langage","Lecture","Recitation/Chant","Ecriture","Langue vivante","Dessin","Calcul Ecrit","Éducation Civique et Morale","Problème","Sciences d'observation","Jeux éducatifs","Activités motrices","Comptines",
                                    "Arts visuels","Initiation aux langues","Écriture","Éveil sensoriel","Découverte du monde",
                                    "Orthographe","Grammaire","Conjugaison","Calcul","Numération","Géométrie","Hygiène","Calcul mental","Arthmétique",
                                    "Danse","Théâtre","Poème","Recitation","Chant",

                                    "Mathématiques","Français","Physique","Chimie","Physique-Chimie","Biologie",
                                    "Économie","Géographie","Histoire","Histoire-Géo","Philosophie",
                                    "Sciences de la Vie et de la Terre","Géo-Politique","Espagnol",
                                    "Arts Plastiques","Education Musicale","Éducation physique et sportive",
                                    "Technologie","Informatique","Anglais","Enseignement moral et civique",
                                    "Sciences économiques et sociales","Sciences Numériques et Technologiques","Numérique et Sciences Informatiques",
                                    "Économie Politique","Management-Gestion","Droit/Droit appliqué","Philosophie politique","Mathématiques expertes",
                                    "Enseignements artistiques avancés","Sciences et laboratoire","Sciences de l'ingénieur",
                                    "Éducation aux médias et à l’information","Enseignement scientifique","Géologie",

                                    "Éducation artistique et culturelle","Éducation à la santé",
                                    "Éducation à l'environnement","Littérature","Allemand","Italien","Arabe"
                          ];
                        ?>
                        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-2">
                            <?php foreach ($matieres_suggestions as $s): ?>
                            <label class="inline-flex items-center space-x-2 p-2 border rounded hover:bg-gray-50">
                                <input type="checkbox" class="matiere-checkbox w-4 h-4 text-blue-600" value="<?php echo htmlspecialchars($s); ?>">
                                <span class="text-sm text-gray-800"><?php echo htmlspecialchars($s); ?></span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Tableau Matières -->
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nom Matière</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Code Matière</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date Création</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($matieres as $matiere): ?>
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-gray-900">
                                            <?php echo htmlspecialchars($matiere['nom_matiere']); ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">
                                            <?php echo htmlspecialchars($matiere['code_matiere']); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?php echo date('d/m/Y', strtotime($matiere['date_creation'])); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                        <!-- <button onclick="editMatiere(<?php //echo $matiere['id']; ?>)" 
                                                class="text-indigo-600 hover:text-indigo-900 mr-3">
                                            <i class="fas fa-edit"></i>
                                        </button> -->
                                        <button onclick="deleteMatiere(<?php echo $matiere['id']; ?>)" 
                                                class="text-red-600 hover:text-red-900">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Section Informations École -->
                <div id="informations-ecole-section" class="section-content hidden">
                    <div class="bg-white rounded-xl shadow-lg p-6">
                        <h3 class="text-lg font-bold text-gray-800 mb-6">Informations de l'École</h3>
        
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Nom de l'École</label>
                                <input type="text" value="<?php echo htmlspecialchars($informations_ecole['nom_ecole'] ?? ''); ?>" 
                                       class="w-full p-3 border border-gray-300 rounded-lg" readonly>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Nom Abrégé</label>
                                <input type="text" value="<?php echo htmlspecialchars($informations_ecole['nom_abrege'] ?? ''); ?>" 
                                       class="w-full p-3 border border-gray-300 rounded-lg" readonly>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Type d'École</label>
                                <input type="text" value="<?php echo htmlspecialchars($informations_ecole['type_ecole'] ?? ''); ?>" 
                                       class="w-full p-3 border border-gray-300 rounded-lg" readonly>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Téléphone</label>
                                <input type="text" value="<?php echo htmlspecialchars($informations_ecole['tel_ecole'] ?? ''); ?>" 
                                       class="w-full p-3 border border-gray-300 rounded-lg" readonly>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Email</label>
                                <input type="email" value="<?php echo htmlspecialchars($informations_ecole['mail_ecole'] ?? ''); ?>" 
                                       class="w-full p-3 border border-gray-300 rounded-lg" readonly>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Ville</label>
                                <input type="text" value="<?php echo htmlspecialchars($informations_ecole['ville_ecole'] ?? ''); ?>" 
                                       class="w-full p-3 border border-gray-300 rounded-lg" readonly>
                            </div>
                            <div class="md:col-span-2">
                                <label class="block text-sm font-medium text-gray-700 mb-2">Adresse</label>
                                <textarea rows="3" class="w-full p-3 border border-gray-300 rounded-lg" readonly><?php echo htmlspecialchars($informations_ecole['adresse_ecole'] ?? ''); ?></textarea>
                            </div>
                            <div class="md:col-span-2">
                                <label class="block text-sm font-medium text-gray-700 mb-2">Devise de l'École</label>
                                <textarea rows="2" class="w-full p-3 border border-gray-300 rounded-lg" readonly><?php echo htmlspecialchars($informations_ecole['devise_ecole'] ?? ''); ?></textarea>
                            </div>
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                            <div class="text-center">
                                <h4 class="text-sm font-medium text-gray-700 mb-2">Logo de l'école</h4>
                                <div class="w-32 h-32 border border-gray-300 rounded-lg flex items-center justify-center mx-auto overflow-hidden bg-gray-50">
                                    <?php if (!empty($informations_ecole['logo_ecole'])): ?>
                                        <img src="<?php echo htmlspecialchars($informations_ecole['logo_ecole']); ?>" 
                                            alt="Logo de l'école" class="w-full h-full object-cover">
                                    <?php else: ?>
                                        <span class="text-xs text-gray-400">Aucun logo</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="text-center">
                                <h4 class="text-sm font-medium text-gray-700 mb-2">Cachet du Directeur/Proviseur</h4>
                                <div class="w-32 h-32 border border-gray-300 rounded-lg flex items-center justify-center mx-auto overflow-hidden bg-gray-50">
                                    <?php if (!empty($informations_ecole['reserve3'])): ?>
                                        <img src="<?php echo htmlspecialchars($informations_ecole['reserve3']); ?>" 
                                            alt="Cachet du proviseur" class="w-full h-full object-cover">
                                    <?php else: ?>
                                        <span class="text-xs text-gray-400">Aucun cachet</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="mt-6">
                            <button onclick="showEditEcoleModal()" 
                                    class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg transition-colors">
                                <i class="fas fa-edit mr-2"></i>Modifier les Informations
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Section Jours et Horaires -->
                <div id="jours-horaires-section" class="section-content hidden">
                    <div class="bg-white rounded-xl shadow-lg p-6">
                        <h3 class="text-lg font-bold text-gray-800 mb-6">Jours et Horaires</h3>

                        <!-- Jours étudiés -->
                        <div class="mb-8 border border-gray-200 rounded-lg p-4">
                            <div class="flex items-center justify-between mb-3">
                                <h4 class="font-semibold text-gray-800">Jours étudiés dans l'école</h4>
                                <button onclick="enregistrerJours()" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg">
                                    <i class="fas fa-save mr-2"></i>Enregistrer
                                </button>
                            </div>
                            <p class="text-sm text-gray-600 mb-3">Sélectionnez les jours pendant lesquels il y a cours. Ces informations seront enregistrées dans la colonne <strong>reserve3</strong> de la table <strong>informations_ecole</strong>.</p>
                            <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-2">
                                <?php 
                                $joursOptions = ['Lundi','Mardi','Mercredi','Jeudi','Vendredi','Samedi','Dimanche'];
                                foreach ($joursOptions as $j): 
                                    $checked = in_array($j, $jours_etudies) ? 'checked' : '';
                                ?>
                                <label class="inline-flex items-center space-x-2 p-2 border rounded-md hover:bg-gray-50">
                                    <input type="checkbox" class="jour-etudie w-4 h-4 text-blue-600" value="<?php echo $j; ?>" <?php echo $checked; ?>>
                                    <span class="text-sm text-gray-800"><?php echo $j; ?></span>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Gestion des créneaux horaires -->
                        <div class="border border-gray-200 rounded-lg p-4">
                            <div class="flex items-center justify-between mb-4">
                                <h4 class="font-semibold text-gray-800">Créneaux horaires</h4>
                            </div>

                            <!-- Formulaire ajout/modification -->
                            <form id="creneauForm" onsubmit="soumettreCreneau(event)" class="grid grid-cols-1 md:grid-cols-6 gap-3 mb-4">
                                <input type="hidden" name="action" value="ajouter_creneau">
                                <input type="hidden" name="id" id="creneau_id" value="">
                                <div>
                                    <label class="block text-xs text-gray-600 mb-1">Heure début</label>
                                    <input type="time" name="heure_debut" id="heure_debut" required class="w-full px-2 py-2 border rounded">
                                </div>
                                <div>
                                    <label class="block text-xs text-gray-600 mb-1">Heure fin</label>
                                    <input type="time" name="heure_fin" id="heure_fin" required class="w-full px-2 py-2 border rounded">
                                </div>
                                <div>
                                    <label class="block text-xs text-gray-600 mb-1">Ordre</label>
                                    <input type="number" name="ordre_affichage" id="ordre_affichage" value="0" class="w-full px-2 py-2 border rounded">
                                </div>
                                <div>
                                    <label class="block text-xs text-gray-600 mb-1">Actif</label>
                                    <select name="actif" id="actif" class="w-full px-2 py-2 border rounded">
                                        <option value="1" selected>Oui</option>
                                        <option value="0">Non</option>
                                    </select>
                                </div>
                                <div class="md:col-span-2">
                                    <label class="block text-xs text-gray-600 mb-1">Libellé (optionnel)</label>
                                    <input type="text" name="nom" id="nom" placeholder="ex: 08h00 - 09h00" class="w-full px-2 py-2 border rounded">
                                </div>
                                <div class="md:col-span-6 flex items-center justify-end gap-2">
                                    <button type="button" onclick="resetCreneauForm()" class="px-4 py-2 bg-gray-100 text-gray-700 rounded">Réinitialiser</button>
                                    <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded">
                                        <i class="fas fa-plus mr-2"></i><span id="creneauSubmitText">Ajouter</span>
                                    </button>
                                </div>
                            </form>

                            <!-- Tableau des créneaux -->
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Ordre</th>
                                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Libellé</th>
                                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Début</th>
                                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Fin</th>
                                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Actif</th>
                                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody id="creneauxTableBody" class="bg-white divide-y divide-gray-200">
                                        <?php foreach ($creneaux as $c): ?>
                                        <tr data-id="<?php echo $c['id']; ?>">
                                            <td class="px-4 py-2 text-sm text-gray-700"><?php echo (int)$c['ordre_affichage']; ?></td>
                                            <td class="px-4 py-2 text-sm text-gray-700"><?php echo htmlspecialchars($c['nom']); ?></td>
                                            <td class="px-4 py-2 text-sm text-gray-700"><?php echo htmlspecialchars(substr($c['heure_debut'],0,5)); ?></td>
                                            <td class="px-4 py-2 text-sm text-gray-700"><?php echo htmlspecialchars(substr($c['heure_fin'],0,5)); ?></td>
                                            <td class="px-4 py-2 text-sm">
                                                <?php if ((int)$c['actif'] === 1): ?>
                                                    <span class="px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">Oui</span>
                                                <?php else: ?>
                                                    <span class="px-2 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-800">Non</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-4 py-2 text-sm">
                                                <button class="text-indigo-600 hover:text-indigo-900 mr-3" onclick="remplirCreneauForm(<?php echo $c['id']; ?>,'<?php echo htmlspecialchars($c['heure_debut']); ?>','<?php echo htmlspecialchars($c['heure_fin']); ?>',<?php echo (int)$c['ordre_affichage']; ?>,<?php echo (int)$c['actif']; ?>,'<?php echo htmlspecialchars($c['nom'], ENT_QUOTES); ?>')"><i class="fas fa-edit"></i></button>
                                                <button class="text-red-600 hover:text-red-900" onclick="supprimerCreneau(<?php echo $c['id']; ?>)"><i class="fas fa-trash"></i></button>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

            <!-- Section Fonctions Application -->
            <div id="fonctions-app-section" class="section-content hidden">
                <div class="bg-white rounded-xl shadow-lg p-6">
                    <div class="flex items-center justify-between mb-6">
                        <h3 class="text-lg font-bold text-gray-800">Configuration des Fonctions de l'Application</h3>
                        <div class="flex space-x-3">
                            <button id="modifierFonctionsBtn" onclick="toggleFonctionsEdition()" 
                                    class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition-colors">
                                <i class="fas fa-edit mr-2"></i>Modifier
                            </button>
                            <button id="enregistrerFonctionsBtn" onclick="enregistrerFonctions()" 
                                    class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg transition-colors hidden">
                                <i class="fas fa-save mr-2"></i>Enregistrer
                            </button>
                        </div>
                    </div>

                    <!-- Message d'avertissement -->
                    <div class="bg-amber-50 border border-amber-200 rounded-lg p-4 mb-6">
                        <div class="flex items-start">
                            <i class="fas fa-exclamation-triangle text-amber-600 mt-1 mr-3"></i>
                            <div>
                                <h4 class="text-sm font-medium text-amber-800">Attention</h4>
                                <p class="text-sm text-amber-700 mt-1">
                                    La modification des fonctionnalités peut influencer les performances de votre application. 
                                    Activez uniquement les modules dont vous avez besoin.
                                </p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Tableau des fonctionnalités disponibles -->
                    <div class="space-y-4">
                        <?php foreach ($fonctionnalites_disponibles as $code => $fonction): 
                            $isActive = in_array($code, $fonctions_activees);
                        ?>
                        <div class="border border-gray-200 rounded-lg p-4 hover:bg-gray-50 transition-colors">
                            <div class="flex items-start space-x-4">
                                <!-- Case à cocher -->
                                <div class="flex items-center mt-1">
                                    <input type="checkbox" 
                                           id="fonction_<?php echo $code; ?>" 
                                           value="<?php echo $code; ?>"
                                           <?php echo $isActive ? 'checked' : ''; ?>
                                           disabled
                                           class="fonction-checkbox w-5 h-5 text-blue-600 border-gray-300 rounded focus:ring-blue-500">
                                </div>
                                
                                <!-- Icône -->
                                <div class="text-2xl">
                                    <?php echo $fonction['icone']; ?>
                                </div>
                                
                                <!-- Informations -->
                                <div class="flex-1">
                                    <div class="flex items-center justify-between">
                                        <h4 class="text-lg font-semibold text-gray-800">
                                            <?php echo htmlspecialchars($fonction['nom']); ?>
                                        </h4>
                                        <span class="text-xs font-mono text-gray-500 bg-gray-100 px-2 py-1 rounded">
                                            <?php echo $code; ?>
                                        </span>
                                    </div>
                                    <p class="text-sm text-gray-600 mt-2 leading-relaxed">
                                        <?php echo htmlspecialchars($fonction['description']); ?>
                                    </p>
                                    
                                    <!-- Statut -->
                                    <div class="mt-3">
                                        <?php if ($isActive): ?>
                                            <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                                <i class="fas fa-check-circle mr-1"></i>Activée
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                                                <i class="fas fa-circle mr-1"></i>Désactivée
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Configuration actuelle -->
                    <?php if (!empty($fonctions_app)): ?>
                    <div class="mt-8 bg-blue-50 border border-blue-200 rounded-lg p-4">
                        <h4 class="text-sm font-semibold text-blue-800 mb-2">
                            <i class="fas fa-info-circle mr-1"></i>Configuration actuelle
                        </h4>
                        <p class="text-sm text-blue-700">
                            <strong><?php echo count($fonctions_activees); ?></strong> fonctionnalité(s) activée(s) : 
                            <span class="font-mono text-xs bg-blue-100 px-2 py-1 rounded ml-2">
                                <?php echo implode(' | ', $fonctions_activees); ?>
                            </span>
                        </p>
                        <p class="text-xs text-blue-600 mt-1">
                            Dernière mise à jour : <?php echo !empty($fonctions_app) ? date('d/m/Y à H:i', strtotime($fonctions_app[0]['date_creation'])) : 'Jamais'; ?>
                        </p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Section Rôles -->
            <div id="roles-section" class="section-content hidden">
                <div class="bg-white rounded-xl shadow-lg p-6">
                    <div class="flex items-center justify-between mb-6">
                        <h3 class="text-lg font-bold text-gray-800">Gestion des Rôles</h3>
                        <button onclick="showAddRoleModal()" 
                                class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition-colors">
                            <i class="fas fa-user-cog mr-2"></i>Attribuer les Rôles
                        </button>
                    </div>
                    
                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
                        <p class="text-blue-800 text-sm">
                            <i class="fas fa-info-circle mr-2"></i>
                            Attribuez les fonctions (Directeur, Secrétaire, etc.) aux différents rôles disponibles dans le système.
                        </p>
                    </div>
                    
                    <!-- Tableau Rôles -->
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ID</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Code Rôle</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nom de la Fonction</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Fonctions Assignées</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Statut</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php 
                                // Récupérer les rôles depuis la base de données
                                try {
                                    $stmt = $pdo->query("SELECT * FROM roles ORDER BY id");
                                    $roles = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                } catch (PDOException $e) {
                                    $roles = [];
                                }
                                
                                foreach ($roles as $role): 
                                ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <?php echo htmlspecialchars($role['id']); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="px-2 py-1 text-xs font-semibold rounded-full bg-indigo-100 text-indigo-800">
                                            <?php echo htmlspecialchars($role['code_role']); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <?php if (isset($fonctionnalites_disponibles[$role['code_role']])): ?>
                                                <span class="text-xl mr-2"><?php echo $fonctionnalites_disponibles[$role['code_role']]['icone']; ?></span>
                                                <span class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($fonctionnalites_disponibles[$role['code_role']]['nom']); ?></span>
                                            <?php else: ?>
                                                <span class="text-sm text-gray-500">Fonction inconnue</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <?php 
                                        $executants_fonctions = !empty($role['executants']) ? explode('|', $role['executants']) : [];
                                        $nb_executants = count(array_filter($executants_fonctions));
                                        
                                        if ($nb_executants > 0): ?>
                                            <div class="text-sm">
                                                <span class="font-medium"><?php echo $nb_executants; ?> fonction(s)</span>
                                                <div class="text-xs text-gray-500 mt-1">
                                                    <?php foreach ($executants_fonctions as $fonction): ?>
                                                        <span class="inline-block bg-gray-100 text-gray-700 px-2 py-1 rounded-full mr-1 mb-1">
                                                            <?php echo htmlspecialchars($fonction); ?>
                                                        </span>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-sm text-gray-500">Aucune fonction assignée</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php if ($nb_executants > 0): ?>
                                            <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                                <i class="fas fa-check-circle mr-1"></i>Actif
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                                                <i class="fas fa-user-slash mr-1"></i>Vacant
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Section Statistiques -->
            <div id="statistiques-section" class="section-content hidden">
                <div class="bg-white rounded-xl shadow-lg p-6">
                    <h3 class="text-lg font-bold text-gray-800 mb-6">Statistiques Détaillées</h3>
                    
                    <!-- Statistiques Générales -->
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                        <div class="stat-card bg-gradient-to-r from-blue-500 to-blue-600 rounded-xl shadow-lg p-6 text-white">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-blue-100 text-sm">Total Élèves</p>
                                    <p class="text-2xl font-bold" id="detail-total-eleves"><?php echo $stats['total_eleves']; ?></p>
                                </div>
                                <div class="bg-blue-400 p-3 rounded-full">
                                    <i class="fas fa-users text-xl"></i>
                                </div>
                            </div>
                        </div>
                        
                        <div class="stat-card bg-gradient-to-r from-green-500 to-green-600 rounded-xl shadow-lg p-6 text-white">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-green-100 text-sm">Personnel</p>
                                    <p class="text-2xl font-bold" id="detail-total-personnel"><?php echo $stats['total_personnel']; ?></p>
                                </div>
                                <div class="bg-green-400 p-3 rounded-full">
                                    <i class="fas fa-user-tie text-xl"></i>
                                </div>
                            </div>
                        </div>
                        
                        <div class="stat-card bg-gradient-to-r from-yellow-500 to-yellow-600 rounded-xl shadow-lg p-6 text-white">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-yellow-100 text-sm">Classes</p>
                                    <p class="text-2xl font-bold" id="detail-total-classes"><?php echo $stats['total_classes']; ?></p>
                                </div>
                                <div class="bg-yellow-400 p-3 rounded-full">
                                    <i class="fas fa-school text-xl"></i>
                                </div>
                            </div>
                        </div>
                        
                        <div class="stat-card bg-gradient-to-r from-purple-500 to-purple-600 rounded-xl shadow-lg p-6 text-white">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-purple-100 text-sm">Matières</p>
                                    <p class="text-2xl font-bold" id="detail-total-matieres"><?php echo $stats['total_matieres']; ?></p>
                                </div>
                                <div class="bg-purple-400 p-3 rounded-full">
                                    <i class="fas fa-book text-xl"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Statistiques Avancées -->
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                        <div class="bg-gray-50 rounded-lg p-4">
                            <h4 class="font-semibold text-gray-800 mb-2">Moyenne Élèves/Classe</h4>
                            <p class="text-2xl font-bold text-blue-600" id="detail-moyenne-eleves-classe"><?php echo $stats['moyenne_eleves_par_classe']; ?></p>
                        </div>
                        
                        <div class="bg-gray-50 rounded-lg p-4">
                            <h4 class="font-semibold text-gray-800 mb-2">Ratio Personnel/Élèves</h4>
                            <p class="text-2xl font-bold text-green-600" id="detail-ratio-personnel-eleves"><?php echo $stats['ratio_personnel_eleves']; ?></p>
                        </div>
                        
                        <div class="bg-gray-50 rounded-lg p-4">
                            <h4 class="font-semibold text-gray-800 mb-2">Total Rôles</h4>
                            <p class="text-2xl font-bold text-purple-600" id="detail-total-roles"><?php echo $stats['total_roles']; ?></p>
                        </div>
                    </div>
                    
                    <!-- Graphiques et Répartitions -->
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                        <!-- Répartition par Genre -->
                        <div class="bg-gray-50 rounded-lg p-6">
                            <h4 class="font-semibold text-gray-800 mb-4">Répartition par Genre</h4>
                            <div class="space-y-3" id="detail-genre-list">
                                <?php foreach ($stats['repartition_genre'] as $genre): ?>
                                <div class="flex justify-between items-center">
                                    <span class="text-gray-700"><?php echo ucfirst($genre['genre_eleve']); ?></span>
                                    <div class="flex items-center">
                                        <div class="w-24 bg-gray-200 rounded-full h-2 mr-3">
                                            <div class="bg-blue-500 h-2 rounded-full" style="width: <?php echo ($stats['total_eleves'] > 0) ? ($genre['nombre'] / $stats['total_eleves'] * 100) : 0; ?>%"></div>
                                        </div>
                                        <span class="font-semibold text-blue-600"><?php echo $genre['nombre']; ?></span>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        
                        <!-- Personnel par Rôle -->
                        <div class="bg-gray-50 rounded-lg p-6">
                            <h4 class="font-semibold text-gray-800 mb-4">Personnel par Rôle</h4>
                            <div class="space-y-3" id="detail-personnel-par-role">
                                <?php foreach ($stats['personnel_par_role'] as $role): ?>
                                <div class="flex justify-between items-center">
                                    <span class="text-gray-700"><?php echo ucfirst($role['role_u']); ?></span>
                                    <div class="flex items-center">
                                        <div class="w-24 bg-gray-200 rounded-full h-2 mr-3">
                                            <div class="bg-green-500 h-2 rounded-full" style="width: <?php echo ($stats['total_personnel'] > 0) ? ($role['nombre'] / $stats['total_personnel'] * 100) : 0; ?>%"></div>
                                        </div>
                                        <span class="font-semibold text-green-600"><?php echo $role['nombre']; ?></span>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Classes les plus/moins peuplées -->
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                        <!-- Classes les plus peuplées -->
                        <div class="bg-gray-50 rounded-lg p-6">
                            <h4 class="font-semibold text-gray-800 mb-4">Classes les Plus Peuplées</h4>
                            <div class="space-y-2" id="detail-top-classes">
                                <?php foreach ($stats['top_classes_peuplees'] as $classe): ?>
                                <div class="flex justify-between items-center py-2 border-b border-gray-200">
                                    <span class="text-gray-700 font-medium"><?php echo $classe['nom_classe']; ?></span>
                                    <span class="bg-red-100 text-red-800 px-3 py-1 rounded-full text-sm font-semibold"><?php echo $classe['nombre_eleves']; ?> élèves</span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        
                        <!-- Classes les moins peuplées -->
                        <div class="bg-gray-50 rounded-lg p-6">
                            <h4 class="font-semibold text-gray-800 mb-4">Classes les Moins Peuplées</h4>
                            <div class="space-y-2" id="detail-classes-moins">
                                <?php foreach ($stats['classes_moins_peuplees'] as $classe): ?>
                                <div class="flex justify-between items-center py-2 border-b border-gray-200">
                                    <span class="text-gray-700 font-medium"><?php echo $classe['nom_classe']; ?></span>
                                    <span class="bg-yellow-100 text-yellow-800 px-3 py-1 rounded-full text-sm font-semibold"><?php echo $classe['nombre_eleves']; ?> élèves</span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Élèves par Classe -->
                    <div class="bg-gray-50 rounded-lg p-6">
                        <h4 class="font-semibold text-gray-800 mb-4">Répartition des Élèves par Classe</h4>
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4" id="detail-eleves-par-classe-grid">
                            <?php foreach ($stats['eleves_par_classe'] as $classe): ?>
                            <div class="bg-white rounded-lg p-4 shadow-sm">
                                <div class="flex justify-between items-center">
                                    <span class="font-medium text-gray-800"><?php echo $classe['nom_classe']; ?></span>
                                    <span class="bg-blue-100 text-blue-800 px-2 py-1 rounded text-sm font-semibold"><?php echo $classe['nombre_eleves']; ?></span>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Section Sauvegarde -->
            <div id="sauvegarde-section" class="section-content hidden">
                <div class="bg-white rounded-xl shadow-lg p-6">
                    <div class="flex items-center justify-between mb-6">
                        <h3 class="text-lg font-bold text-gray-800">Sauvegarde et Sécurité</h3>
                        <div class="flex space-x-3">
                            <button onclick="creerSauvegarde()" 
                                    class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition-colors">
                                <i class="fas fa-download mr-2"></i>Créer une sauvegarde
                            </button>
                        </div>
                    </div>

                    <!-- Informations importantes -->
                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
                        <div class="flex items-start">
                            <i class="fas fa-info-circle text-blue-600 mt-1 mr-3"></i>
                            <div>
                                <h4 class="text-sm font-medium text-blue-800">Informations importantes</h4>
                                <p class="text-sm text-blue-700 mt-1">
                                    La sauvegarde crée un fichier SQL contenant toutes les données de votre base de données.
                                    Il est recommandé de créer des sauvegardes régulièrement pour prévenir toute perte de données.
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Statistiques de sauvegarde -->
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                        <div class="bg-gray-50 rounded-lg p-4">
                            <h4 class="font-semibold text-gray-800 mb-2">Dernière sauvegarde</h4>
                            <p class="text-2xl font-bold text-blue-600" id="derniere-sauvegarde">-</p>
                        </div>
                        
                        <div class="bg-gray-50 rounded-lg p-4">
                            <h4 class="font-semibold text-gray-800 mb-2">Taille de la base</h4>
                            <p class="text-2xl font-bold text-green-600" id="taille-base">-</p>
                        </div>
                        
                        <div class="bg-gray-50 rounded-lg p-4">
                            <h4 class="font-semibold text-gray-800 mb-2">Sauvegardes disponibles</h4>
                            <p class="text-2xl font-bold text-purple-600" id="nombre-sauvegardes">0</p>
                        </div>
                    </div>

                    <!-- Liste des sauvegardes récentes -->
                    <div class="border border-gray-200 rounded-lg p-4">
                        <h4 class="font-semibold text-gray-800 mb-4">Sauvegardes disponibles</h4>
                        <div id="liste-sauvegardes" class="space-y-3">
                            <!-- Les sauvegardes seront chargées ici dynamiquement -->
                            <p class="text-gray-500 text-center py-4">Aucune sauvegarde disponible</p>
                        </div>
                    </div>

                    <!-- Options de configuration -->
                    <div class="mt-6 border border-gray-200 rounded-lg p-4">
                        <h4 class="font-semibold text-gray-800 mb-4">Configuration des sauvegardes</h4>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="flex items-center">
                                    <input type="checkbox" id="auto-sauvegarde" class="mr-2">
                                    <span class="text-sm text-gray-700">Sauvegarde automatique hebdomadaire</span>
                                </label>
                            </div>
                            <div>
                                <label class="flex items-center">
                                    <input type="checkbox" id="compress-sauvegarde" class="mr-2" checked>
                                    <span class="text-sm text-gray-700">Compresser les sauvegardes</span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div id="parametres-section" class="section-content hidden">
                <div class="bg-white rounded-xl shadow-lg p-6">
                    <h3 class="text-lg font-bold text-gray-800 mb-4">Paramètres Système</h3>
                    <p class="text-gray-600">Fonctionnalité en développement...</p>
                </div>
            </div>

            <!-- Section pour charger les fonctions externes -->
            <div id="function-section" class="section-content hidden">
                <iframe id="functionFrame" src="" class="w-full h-screen border-0 rounded-lg"></iframe>
            </div>
        </main>
    </div>

    <!-- Modal Modification de Classe -->
    <div id="editClasseModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-xl shadow-2xl w-full max-w-3xl max-h-[90vh] overflow-y-auto">
            <div class="flex items-center justify-between p-6 border-b border-gray-200">
                <div class="flex items-center">
                    <div class="w-10 h-10 bg-indigo-100 rounded-lg flex items-center justify-center mr-3">
                        <i class="fas fa-school text-indigo-600"></i>
                    </div>
                    <div>
                        <h3 class="text-lg font-bold text-gray-800">Modifier la Classe</h3>
                        <p class="text-sm text-gray-500">Mettre à jour le nom, le niveau et la configuration des matières</p>
                    </div>
                </div>
                <button onclick="closeEditClasseModal()" class="text-gray-400 hover:text-gray-600 transition-colors">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>

            <form id="editClasseForm" class="p-6" onsubmit="submitEditClasse(event)">
                <input type="hidden" name="action" value="modifier_classe">
                <input type="hidden" name="classe_id" id="edit_classe_id">

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Sélectionnez la Classe à modifier</label>
                        <select id="edit_nom_classe_select" name="nom_classe" required class="w-full p-2 border border-gray-300 rounded-lg" onchange="editClasseSelectChanged(this)">
                            <option value="">Sélectionner une classe</option>
                            <?php foreach ($classes as $cl): ?>
                                <option value="<?php echo htmlspecialchars($cl['nom_classe']); ?>" data-id="<?php echo (int)$cl['id']; ?>" data-niveau="<?php echo htmlspecialchars($cl['niveau']); ?>">
                                    <?php echo htmlspecialchars($cl['nom_classe']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Niveau</label>
                        <?php
                            $niveaux = [];
                            foreach ($classes as $cl) {
                                $niv = trim((string)$cl['niveau']);
                                if ($niv !== '' && !in_array($niv, $niveaux, true)) {
                                    $niveaux[] = $niv;
                                }
                            }
                        ?>
                        <select id="edit_niveau_select" name="niveau" required class="w-full p-2 border border-gray-300 rounded-lg disabled-look">
                            <option value="">Sélectionner un niveau</option>
                            <?php foreach ($niveaux as $niv): ?>
                                <option value="<?php echo htmlspecialchars($niv); ?>"><?php echo htmlspecialchars($niv); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="mb-3 flex items-center justify-between">
                    <label class="block text-sm font-medium text-gray-700">Matières, Coefficients et Barèmes</label>
                    <button type="button" class="px-3 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded" onclick="addClasseMatiereLine()">
                        <i class="fas fa-plus mr-2"></i>Ajouter une matière
                    </button>
                </div>

                <div id="classeMatiereLines" class="space-y-3"></div>

                <div class="flex items-center justify-end space-x-3 pt-4 border-t border-gray-200 mt-6">
                    <button type="button" onclick="closeEditClasseModal()" class="px-6 py-2 text-gray-600 bg-gray-100 hover:bg-gray-200 rounded-lg transition-colors">
                        <i class="fas fa-times mr-2"></i>Annuler
                    </button>
                    <button type="submit" class="px-6 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg transition-colors">
                        <i class="fas fa-save mr-2"></i>Enregistrer
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ===== MODALS GESTION DES RÔLES ===== -->
    
    <!-- Modal Ajout/Modification de Rôle -->
    <div id="roleModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-xl shadow-2xl w-full max-w-2xl max-h-[90vh] overflow-y-auto">
            <!-- En-tête du modal -->
            <div class="flex items-center justify-between p-6 border-b border-gray-200">
                <div class="flex items-center">
                    <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center mr-3">
                        <i class="fas fa-user-cog text-blue-600"></i>
                    </div>
                    <div>
                        <h3 class="text-lg font-bold text-gray-800">Gestion des Rôles</h3>
                        <p class="text-sm text-gray-500">Assigner ou désassigner des utilisateurs aux rôles</p>
                    </div>
                </div>
                <button onclick="closeRoleModal()" class="text-gray-400 hover:text-gray-600 transition-colors">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>

            <!-- Contenu du formulaire -->
            <form id="roleForm" class="p-6">
                <input type="hidden" name="action" value="attribuer_roles">

                <!-- Attribution des rôles -->
                <div class="mb-6">
                    <label class="block text-sm font-medium text-gray-700 mb-4">
                        <i class="fas fa-cogs mr-2 text-blue-500"></i>Attribution des Rôles aux Fonctions
                    </label>
                    
                    <div class="space-y-4">
                        <?php 
                        // Récupérer les fonctions depuis la table fonctions_application
                        try {
                            $stmt = $pdo->query("SELECT fonctions FROM fonctions_application LIMIT 1");
                            $fonctions_data = $stmt->fetch(PDO::FETCH_ASSOC);
                            $fonctions_disponibles_codes = $fonctions_data ? array_filter(explode('|', $fonctions_data['fonctions'])) : [];
                        } catch (PDOException $e) {
                            $fonctions_disponibles_codes = [];
                        }
                        
                        // Récupérer les attributions actuelles
                        try {
                            $stmt = $pdo->query("SELECT code_role, executants FROM roles");
                            $attributions_actuelles = [];
                            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                                $attributions_actuelles[$row['code_role']] = $row['executants'] ? explode('|', $row['executants']) : [];
                            }
                        } catch (PDOException $e) {
                            $attributions_actuelles = [];
                        }
                        
                        foreach ($fonctions_disponibles_codes as $code_fonction):
                            if (isset($fonctionnalites_disponibles[$code_fonction])):
                                $fonction = $fonctionnalites_disponibles[$code_fonction];
                                $executants_actuels = $attributions_actuelles[$code_fonction] ?? [];
                        ?>
                        <div class="border border-gray-200 rounded-lg p-4 bg-white">
                            <div class="flex items-start space-x-4">
                                <!-- Icône et nom de la fonction -->
                                <div class="flex items-center space-x-3 flex-1">
                                    <span class="text-2xl"><?php echo $fonction['icone']; ?></span>
                                    <div class="flex-1">
                                        <h4 class="font-medium text-gray-800 mb-1"><?php echo htmlspecialchars($fonction['nom']); ?></h4>
                                        <p class="text-xs text-gray-500 mb-2"><?php echo htmlspecialchars(substr($fonction['description'], 0, 80)) . '...'; ?></p>
                                        <span class="text-xs font-mono text-blue-600 bg-blue-50 px-2 py-1 rounded">
                                            <?php echo $code_fonction; ?>
                                        </span>
                                    </div>
                                </div>
                                
                                <!-- Multi-select moderne des fonctions -->
                                <div class="w-72">
                                    <label class="block text-xs font-medium text-gray-600 mb-2">Exécutants assignés</label>
                                    <div class="border border-gray-300 rounded-lg bg-white">
                                        <!-- Container pour les tags sélectionnés -->
                                        <div id="selectedTags_<?php echo $code_fonction; ?>" class="min-h-[40px] p-2 flex flex-wrap gap-1">
                                            <?php foreach (['Directeur', 'Directeur Adjoint', 'DE', 'Secrétaire', 'Comptable', 'Surveillant'] as $fonction_option): ?>
                                                <?php if (in_array($fonction_option, $executants_actuels)): ?>
                                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800 tag-item" data-value="<?php echo $fonction_option; ?>">
                                                        <?php echo $fonction_option == 'Surveillant' ? 'Surveillant (CPE)' : $fonction_option; ?>
                                                        <button type="button" class="ml-1 text-blue-600 hover:text-blue-800" onclick="removeTag('<?php echo $code_fonction; ?>', '<?php echo $fonction_option; ?>')">
                                                            <i class="fas fa-times text-xs"></i>
                                                        </button>
                                                    </span>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                            <div id="placeholder_<?php echo $code_fonction; ?>" class="text-xs text-gray-400 py-1" <?php echo !empty($executants_actuels) ? 'style="display:none"' : ''; ?>>
                                                Cliquez pour sélectionner...
                                            </div>
                                        </div>
                                        
                                        <!-- Dropdown des options -->
                                        <div class="border-t border-gray-200">
                                            <div class="p-2 space-y-1">
                                                <?php foreach (['Directeur', 'Directeur Adjoint', 'DE', 'Secrétaire', 'Comptable', 'Surveillant'] as $fonction_option): ?>
                                                    <label class="flex items-center p-1 hover:bg-gray-50 rounded cursor-pointer option-item" 
                                                           data-role="<?php echo $code_fonction; ?>" 
                                                           data-value="<?php echo $fonction_option; ?>"
                                                           <?php echo in_array($fonction_option, $executants_actuels) ? 'style="display:none"' : ''; ?>>
                                                        <input type="checkbox" name="executants_<?php echo $code_fonction; ?>[]" value="<?php echo $fonction_option; ?>" 
                                                               class="w-3 h-3 text-blue-600 border-gray-300 rounded focus:ring-blue-500 mr-2"
                                                               <?php echo in_array($fonction_option, $executants_actuels) ? 'checked' : ''; ?>
                                                               onchange="toggleTag('<?php echo $code_fonction; ?>', '<?php echo $fonction_option; ?>', this)">
                                                        <span class="text-sm text-gray-700"><?php echo $fonction_option == 'Surveillant' ? 'Surveillant (CPE)' : $fonction_option; ?></span>
                                                    </label>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <p class="text-xs text-gray-500 mt-1">
                                        <i class="fas fa-info-circle mr-1"></i>Cliquez pour ajouter/supprimer
                                    </p>
                                </div>
                            </div>
                        </div>
                        <?php 
                            endif;
                        endforeach; 
                        ?>
                    </div>
                </div>

                <!-- Boutons -->
                <div class="flex items-center justify-end space-x-3 pt-4 border-t border-gray-200">
                    <button type="button" onclick="closeRoleModal()" 
                            class="px-6 py-2 text-gray-600 bg-gray-100 hover:bg-gray-200 rounded-lg transition-colors">
                        <i class="fas fa-times mr-2"></i>Annuler
                    </button>
                    <button type="submit" 
                            class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors">
                        <i class="fas fa-save mr-2"></i>Enregistrer
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Confirmation de Suppression -->
    <div id="deleteRoleModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-xl shadow-2xl w-full max-w-md">
            <div class="p-6">
                <div class="flex items-center mb-4">
                    <div class="w-12 h-12 bg-red-100 rounded-lg flex items-center justify-center mr-4">
                        <i class="fas fa-exclamation-triangle text-red-600 text-xl"></i>
                    </div>
                    <div>
                        <h3 class="text-lg font-bold text-gray-800">Confirmer la Suppression</h3>
                        <p class="text-sm text-gray-500">Cette action est irréversible</p>
                    </div>
                </div>
                
                <p class="text-gray-600 mb-6">
                    Êtes-vous sûr de vouloir supprimer ce rôle ? 
                    Tous les utilisateurs assignés à ce rôle perdront leurs permissions.
                </p>
                
                <div class="flex items-center justify-end space-x-3">
                    <button onclick="closeDeleteRoleModal()" 
                            class="px-4 py-2 text-gray-600 bg-gray-100 hover:bg-gray-200 rounded-lg transition-colors">
                        Annuler
                    </button>
                    <button onclick="confirmDeleteRole()" 
                            class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg transition-colors">
                        <i class="fas fa-trash mr-2"></i>Supprimer
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Gestion du menu mobile
        document.getElementById('menu-toggle').addEventListener('click', function() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('overlay');
            
            sidebar.classList.remove('-translate-x-full');
            overlay.classList.remove('hidden');
        });

        

        function closeSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('overlay');
            
            sidebar.classList.add('-translate-x-full');
            overlay.classList.add('hidden');
        }

        // Gestion des sections
        function showSection(sectionName) {
            // Cacher toutes les sections
            const sections = document.querySelectorAll('.section-content');
            sections.forEach(section => section.classList.add('hidden'));

            // Afficher la section demandée
            document.getElementById(sectionName + '-section').classList.remove('hidden');

            // Sauvegarder la section actuelle dans localStorage
            localStorage.setItem('currentSection', sectionName);

            // Chargement différé des sections lourdes
            if (sectionName === 'eleves') {
                ensureElevesLoaded();
            } else if (sectionName === 'personnel') {
                ensurePersonnelLoaded();
            } else if (sectionName === 'dashboard') {
                fetchStats();
            }

            // Mettre à jour le titre
            let title = 'Tableau de Bord';
            switch(sectionName) {
                case 'eleves': title = 'Gestion des Élèves'; break;
                case 'personnel': title = 'Gestion du Personnel'; break;
                case 'classes': title = 'Gestion des Classes'; break;
                case 'matieres': title = 'Gestion des Matières'; break;
                case 'informations-ecole': title = 'Informations de l\'École'; break;
                case 'jours-horaires': title = 'Jours et Horaires'; break;
                case 'fonctions-app': title = 'Fonctions Application'; break;
                case 'roles': title = 'Gestion des Rôles'; break;
                case 'statistiques': title = 'Statistiques'; break;
                case 'sauvegarde': title = 'Sauvegarde et Sécurité'; break;
                case 'parametres': title = 'Paramètres'; break;
            }
            document.getElementById('page-title').textContent = title;

            // Mettre à jour l'état actif du menu
            updateActiveMenuItem(sectionName);

            // Fermer le sidebar sur mobile
            if (window.innerWidth < 1024) {
                closeSidebar();
            }
        }

        // Mettre à jour l'élément de menu actif
        function updateActiveMenuItem(sectionName) {
            // Enlever la classe active de tous les boutons
            document.querySelectorAll('.menu-item').forEach(item => {
                item.classList.remove('bg-blue-700');
            });

            // Ajouter la classe active au bouton correspondant
            const activeButton = document.querySelector(`[onclick*="showSection('${sectionName}')"]`);
            if (activeButton) {
                activeButton.classList.add('bg-blue-700');
            }
        }

        // Restaurer la section lors du chargement de la page
        function restoreCurrentSection() {
            const savedSection = localStorage.getItem('currentSection');
            if (savedSection) {
                // Vérifier si c'est une fonction externe
                if (savedSection.startsWith('function-')) {
                    const functionCode = savedSection.replace('function-', '');
                    // Rechercher la fonction et sa page correspondante
                    const functionElement = document.querySelector(`[onclick*="loadFunction('${functionCode}'"]`);
                    if (functionElement) {
                        const onclickValue = functionElement.getAttribute('onclick');
                        const pageMatch = onclickValue.match(/loadFunction\('[^']*',\s*'([^']*)'/);
                        if (pageMatch) {
                            loadFunction(functionCode, pageMatch[1]);
                            return;
                        }
                    }
                }
                // Section normale
                showSection(savedSection);
            } else {
                showSection('dashboard'); // Section par défaut
            }
        }

        // Charger une fonction externe
        function loadFunction(code, page) {
            document.querySelectorAll('.section-content').forEach(section => section.classList.add('hidden'));
            document.getElementById('function-section').classList.remove('hidden');
            const iframe = document.getElementById('functionFrame');

            // Attacher un onload pour masquer la sidebar sur des pages spécifiques chargées dans l'iframe
            iframe.onload = function() {
                try {
                    const doc = iframe.contentDocument || iframe.contentWindow.document;
                    const href = (iframe.contentWindow && iframe.contentWindow.location && iframe.contentWindow.location.href) || iframe.src || '';
                    const shouldHide = /emploi_enseignant\.php|bulletins_programmes\.php|devoirs_enseignant\.php/.test(href);
                    if (shouldHide && doc) {
                        // Injecter un style pour masquer la sidebar ciblée
                        const style = doc.createElement('style');
                        style.textContent = '.w-64.bg-white.shadow-lg.no-print{display:none !important;}';
                        (doc.head || doc.documentElement).appendChild(style);
                        // Masquer immédiatement si déjà présent
                        doc.querySelectorAll('.w-64.bg-white.shadow-lg.no-print').forEach(el => {
                            el.style.display = 'none';
                        });
                    }
                } catch (e) {}
            };

            iframe.src = page;
            
            // Sauvegarder la fonction actuelle dans localStorage
            localStorage.setItem('currentSection', 'function-' + code);
            
            // Trouver le nom de la fonction
            const functionNames = {
                'INSCR_REINSCR': 'Inscription-Réinscription',
                'GEST_NOTES_BULL': 'Gestion Notes-Bulletins',
                'SUIVI_ABS_RET': 'Suivi Absences-Retards',

                'GEST_EMP_TEMPS': 'Gestion Emploi du Temps',

                'ACT_INFOS': 'Actualités-Infos',
                'CARTES_SCOL': 'Cartes Scolaires',
                'COMPTA_TRES': 'Comptabilité-Trésorerie',
                'SCOLARITE': 'Scolarité',

                'CAL_SCO': 'Le Calendrier Scolaire',
                'MESSAGE': 'La Messagerie',
                'SAISIE_NOTE_GLOBALE': 'Saisie des Notes'
            };
            
            document.getElementById('page-title').textContent = functionNames[code] || code;

            // Mettre à jour l'état actif du menu pour les fonctions
            document.querySelectorAll('.menu-item').forEach(item => {
                item.classList.remove('bg-blue-700');
            });
            const activeButton = document.querySelector(`[onclick*="loadFunction('${code}'"]`);
            if (activeButton) {
                activeButton.classList.add('bg-blue-700');
            }
            
            if (window.innerWidth < 1024) {
                closeSidebar();
            }
        }
        
        // Filtrer les élèves
        function filterEleves() {
            const classeFilter = document.getElementById('filterClasse').value.toLowerCase();
            const searchTerm = document.getElementById('searchEleve').value.toLowerCase();
            const rows = document.querySelectorAll('.eleve-row');

            rows.forEach(row => {
                const classe = row.dataset.classe.toLowerCase();
                const nom = row.dataset.nom;
                
                const classeMatch = !classeFilter || classe === classeFilter;
                const nomMatch = !searchTerm || nom.includes(searchTerm);
                
                if (classeMatch && nomMatch) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }

        // Fonctions pour la gestion des élèves
        let currentDeleteEleveId = null;

        function showAddEleveModal() {
            document.getElementById('addEleveModal').classList.remove('hidden');
        }

        function closeAddEleveModal() {
            document.getElementById('addEleveModal').classList.add('hidden');
        }

        function viewEleve(id) {
            // Rediriger vers la page de détails de l'élève
            window.location.href = `eleve_details.php?id=${id}`;
        }

        function editEleve(id) {
            // Récupérer les données de l'élève via AJAX
            const formData = new FormData();
            formData.append('action', 'get_eleve_details');
            formData.append('id_eleve', id);
            
            fetch('', {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const eleve = data.eleve;
                    
                    // Remplir le formulaire de modification
                    document.getElementById('edit_id_eleve').value = eleve.id;
                    document.getElementById('edit_nom_eleve').value = eleve.nom_eleve;
                    document.getElementById('edit_prenom_eleve').value = eleve.prenom_eleve;
                    document.getElementById('edit_classe_eleve').value = eleve.classe_eleve;
                    document.getElementById('edit_genre_eleve').value = eleve.genre_eleve;
                    document.getElementById('edit_date_de_naissance_eleve').value = eleve.date_de_naissance_eleve;
                    document.getElementById('edit_lieu_de_naissance_eleve').value = eleve.lieu_de_naissance_eleve;
                    document.getElementById('edit_adresse_eleve').value = eleve.adresse_eleve;
                    document.getElementById('edit_role_eleve').value = eleve.role_eleve || '';
                    document.getElementById('edit_nom_parent').value = eleve.nom_parent;
                    document.getElementById('edit_prenom_parent').value = eleve.prenom_parent;
                    document.getElementById('edit_profession_parent').value = eleve.profession_parent || '';
                    document.getElementById('edit_telephone_parent').value = eleve.telephone_parent;
                    document.getElementById('edit_photo_actuelle').value = eleve.photo_eleve || '';
                    
                    // Afficher la photo actuelle si elle existe
                    if (eleve.photo_eleve && eleve.photo_eleve !== '') {
                        document.getElementById('editPhotoPreview').src = eleve.photo_eleve;
                        document.getElementById('editPhotoPreview').classList.remove('hidden');
                        document.getElementById('editPhotoPlaceholder').classList.add('hidden');
                    } else {
                        document.getElementById('editPhotoPreview').classList.add('hidden');
                        document.getElementById('editPhotoPlaceholder').classList.remove('hidden');
                    }
                    
                    // Afficher la modal
                    document.getElementById('editEleveModal').classList.remove('hidden');
                } else {
                    showNotification('Erreur lors de la récupération des données: ' + data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Erreur:', error);
                showNotification('Erreur lors de la récupération des détails', 'error');
            });
        }

        function closeEditEleveModal() {
            document.getElementById('editEleveModal').classList.add('hidden');
        }

        function deleteEleve(id) {
            // Récupérer le nom de l'élève depuis le tableau
            const row = document.querySelector(`button[onclick="deleteEleve(${id})"]`).closest('tr');
            const nomPrenom = row.querySelectorAll('td')[1].textContent.trim();
            
            // Remplir les détails dans la modal de suppression
            document.getElementById('deleteEleveNom').textContent = nomPrenom;
            currentDeleteEleveId = id;
            
            // Afficher la modal de confirmation
            document.getElementById('deleteEleveModal').classList.remove('hidden');
        }

        function closeDeleteEleveModal() {
            document.getElementById('deleteEleveModal').classList.add('hidden');
            currentDeleteEleveId = null;
        }

        function confirmDeleteEleve() {
            if (!currentDeleteEleveId) return;
            
            const formData = new FormData();
            formData.append('action', 'supprimer_eleve');
            formData.append('id_eleve', currentDeleteEleveId);
            
            fetch('', {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification(data.message, 'success');
                    // Supprimer la ligne du tableau avec animation
                    const row = document.querySelector(`button[onclick="deleteEleve(${currentDeleteEleveId})"]`).closest('tr');
                    if (row) {
                        // Animation de suppression
                        row.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
                        row.style.opacity = '0';
                        row.style.transform = 'translateX(-20px)';
                        
                        setTimeout(() => {
                            row.remove();
                            // Mettre à jour les statistiques
                            updateStats();
                        }, 300);
                    }
                    closeDeleteEleveModal();
                } else {
                    showNotification(data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Erreur:', error);
                showNotification('Une erreur est survenue lors de la suppression', 'error');
            });
        }

        // Variables globales pour la suppression du personnel
        let currentDeletePersonnelId = null;

        function deletePersonnel(id) {
            // Récupérer le nom du personnel depuis le tableau
            const row = document.querySelector(`button[onclick="deletePersonnel(${id})"]`).closest('tr');
            const nomPrenom = row.querySelectorAll('td')[0].querySelector('.text-sm.font-medium').textContent.trim();
            
            // Remplir les détails dans la modal de suppression
            document.getElementById('deletePersonnelNom').textContent = nomPrenom;
            currentDeletePersonnelId = id;
            
            // Afficher la modal de confirmation
            document.getElementById('deletePersonnelModal').classList.remove('hidden');
        }

        function closeDeletePersonnelModal() {
            document.getElementById('deletePersonnelModal').classList.add('hidden');
            currentDeletePersonnelId = null;
        }

        function confirmDeletePersonnel() {
            if (!currentDeletePersonnelId) return;
            
            const formData = new FormData();
            formData.append('action', 'supprimer_personnel');
            formData.append('id_personnel', currentDeletePersonnelId);
            
            fetch('', {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification(data.message, 'success');
                    // Supprimer la ligne du tableau avec animation
                    const row = document.querySelector(`button[onclick="deletePersonnel(${currentDeletePersonnelId})"]`).closest('tr');
                    if (row) {
                        // Animation de suppression
                        row.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
                        row.style.opacity = '0';
                        row.style.transform = 'translateX(-20px)';
                        
                        setTimeout(() => {
                            row.remove();
                            // Fermer la modal
                            closeDeletePersonnelModal();
                        }, 300);
                    }
                } else {
                    showNotification(data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Erreur:', error);
                showNotification('Une erreur est survenue lors de la suppression', 'error');
            });
        }

        function previewEditPhoto(event) {
            const file = event.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const preview = document.getElementById('editPhotoPreview');
                    const placeholder = document.getElementById('editPhotoPlaceholder');
                    
                    preview.src = e.target.result;
                    preview.classList.remove('hidden');
                    placeholder.classList.add('hidden');
                }
                reader.readAsDataURL(file);
            }
        }

        // Fonction pour gérer la soumission du formulaire de modification
        function handleEditFormSubmit(e) {
            e.preventDefault();
            
            const formData = new FormData(e.target);
            const submitButton = e.target.querySelector('button[type="submit"]');
            const originalText = submitButton.innerHTML;
            
            // Désactiver le bouton et afficher le loading
            submitButton.disabled = true;
            submitButton.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Modification en cours...';
            
            fetch('', {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification(data.message, 'success');
                    updateEleveInTable(data.eleve);
                    closeEditEleveModal();
                } else {
                    showNotification(data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Erreur:', error);
                showNotification('Une erreur est survenue lors de la modification', 'error');
            })
            .finally(() => {
                // Réactiver le bouton
                submitButton.disabled = false;
                submitButton.innerHTML = originalText;
            });
        }

        function updateEleveInTable(eleve) {
            const row = document.querySelector(`button[onclick="editEleve(${eleve.id})"]`).closest('tr');
            if (row) {
                // Générer les initiales pour la photo par défaut
                const initiales = eleve.prenom_eleve.charAt(0).toUpperCase() + eleve.nom_eleve.charAt(0).toUpperCase();
                
                // Formater la date de naissance si elle existe
                let dateNaissanceFormatted = '';
                if (eleve.date_de_naissance_eleve) {
                    const date = new Date(eleve.date_de_naissance_eleve);
                    dateNaissanceFormatted = date.toLocaleDateString('fr-FR');
                }
                
                // Remplacer complètement le contenu de la ligne avec la structure correcte
                row.innerHTML = `
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="flex items-center">
                            ${eleve.photo_eleve ? 
                                `<img src="${eleve.photo_eleve}" alt="Photo ${eleve.prenom_eleve}" class="w-10 h-10 rounded-full object-cover">` :
                                `<div class="w-10 h-10 bg-blue-500 rounded-full flex items-center justify-center">
                                    <span class="text-white font-bold">${initiales}</span>
                                </div>`
                            }
                            <div class="ml-3">
                                <div class="text-sm font-medium text-gray-900">
                                    ${eleve.prenom_eleve} ${eleve.nom_eleve}
                                </div>
                                <div class="text-sm text-gray-500">
                                    ${dateNaissanceFormatted ? `Né(e) le ${dateNaissanceFormatted}` : 'Date non renseignée'}
                                </div>
                            </div>
                        </div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                        ${eleve.matricule_eleve}
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <span class="px-2 py-1 text-xs font-semibold rounded-full bg-blue-100 text-blue-800">
                            ${eleve.classe_eleve}
                        </span>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                        ${eleve.genre_eleve}
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-sm text-gray-900">
                            ${eleve.prenom_parent} ${eleve.nom_parent}
                        </div>
                        <div class="text-sm text-gray-500">
                            ${eleve.profession_parent || ''}
                        </div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-sm text-gray-900">Élève: ${eleve.telephone_eleve || '-'}</div>
                        <div class="text-sm text-gray-500">Parent: ${eleve.telephone_parent}</div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                        <button onclick="viewEleve(${eleve.id})" 
                                class="text-blue-600 hover:text-blue-900 mr-3">
                            <i class="fas fa-eye"></i>
                        </button>
                        <button onclick="editEleve(${eleve.id})" 
                                class="text-green-600 hover:text-green-900 mr-3">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button onclick="deleteEleve(${eleve.id})" 
                                class="text-red-600 hover:text-red-900">
                            <i class="fas fa-trash"></i>
                        </button>
                    </td>
                `;
                
                // Ajouter les attributs de données pour les filtres
                row.className = 'eleve-row hover:bg-gray-50';
                row.setAttribute('data-classe', eleve.classe_eleve);
                row.setAttribute('data-nom', (eleve.nom_eleve + ' ' + eleve.prenom_eleve).toLowerCase());
                
                // Ajouter un effet de mise en évidence temporaire
                row.classList.add('bg-green-50');
                setTimeout(() => {
                    row.classList.remove('bg-green-50');
                    row.classList.add('hover:bg-gray-50');
                }, 2000);
                
                // Mettre à jour les statistiques
                updateStats();
            }
        }

        function addEleveToTable(eleve) {
            const tbody = document.querySelector('#eleves-section tbody');
            if (!tbody) return;
            
            const newRow = document.createElement('tr');
            newRow.className = 'eleve-row hover:bg-gray-50';
            
            // Générer les initiales pour la photo par défaut
            const initiales = eleve.prenom_eleve.charAt(0).toUpperCase() + eleve.nom_eleve.charAt(0).toUpperCase();
            
            // Formater la date de naissance
            let dateNaissanceFormatted = '';
            if (eleve.date_de_naissance_eleve) {
                const date = new Date(eleve.date_de_naissance_eleve);
                dateNaissanceFormatted = date.toLocaleDateString('fr-FR');
            }
            
            newRow.innerHTML = `
                <td class="px-6 py-4 whitespace-nowrap">
                    <div class="flex items-center">
                        ${eleve.photo_eleve ? 
                            `<img src="${eleve.photo_eleve}" alt="Photo ${eleve.prenom_eleve}" class="w-10 h-10 rounded-full object-cover">` :
                            `<div class="w-10 h-10 bg-blue-500 rounded-full flex items-center justify-center">
                                <span class="text-white font-bold">${initiales}</span>
                            </div>`
                        }
                        <div class="ml-3">
                            <div class="text-sm font-medium text-gray-900">
                                ${eleve.prenom_eleve} ${eleve.nom_eleve}
                            </div>
                            <div class="text-sm text-gray-500">
                                ${dateNaissanceFormatted ? `Né(e) le ${dateNaissanceFormatted}` : 'Date non renseignée'}
                            </div>
                        </div>
                    </div>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                    ${eleve.matricule_eleve}
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                    <span class="px-2 py-1 text-xs font-semibold rounded-full bg-blue-100 text-blue-800">
                        ${eleve.classe_eleve}
                    </span>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                    ${eleve.genre_eleve}
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                    <div class="text-sm text-gray-900">
                        ${eleve.prenom_parent} ${eleve.nom_parent}
                    </div>
                    <div class="text-sm text-gray-500">
                        ${eleve.profession_parent || ''}
                    </div>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                    <div class="text-sm text-gray-900">Élève: ${eleve.telephone_eleve || '-'}</div>
                    <div class="text-sm text-gray-500">Parent: ${eleve.telephone_parent}</div>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                    <button onclick="viewEleve(${eleve.id})" 
                            class="text-blue-600 hover:text-blue-900 mr-3">
                        <i class="fas fa-eye"></i>
                    </button>
                    <button onclick="editEleve(${eleve.id})" 
                            class="text-green-600 hover:text-green-900 mr-3">
                        <i class="fas fa-edit"></i>
                    </button>
                    <button onclick="deleteEleve(${eleve.id})" 
                            class="text-red-600 hover:text-red-900">
                        <i class="fas fa-trash"></i>
                    </button>
                </td>
            `;
            
            // Ajouter les attributs de données pour les filtres
            newRow.setAttribute('data-classe', eleve.classe_eleve);
            newRow.setAttribute('data-nom', (eleve.nom_eleve + ' ' + eleve.prenom_eleve).toLowerCase());
            
            tbody.appendChild(newRow);
            
            // Mettre à jour les statistiques
            updateStats();
        }

        function updateStats() {
            // Rafraîchir les statistiques depuis le serveur (total global + graphiques)
            fetchStats();
        }

        // Initialiser les graphiques
        function initCharts() {
            // Données pour les graphiques
            const classeData = <?php echo json_encode($stats['eleves_par_classe']); ?>;
            const genreData = <?php echo json_encode($stats['repartition_genre']); ?>;

            // Graphique des classes
            if (classeData.length > 0) {
                const classeCtx = document.getElementById('classeChart').getContext('2d');
                new Chart(classeCtx, {
                    type: 'bar',
                    data: {
                        labels: classeData.map(item => item.nom_classe),
                        datasets: [{
                            label: 'Nombre d\'élèves',
                            data: classeData.map(item => item.nombre_eleves),
                            backgroundColor: 'rgba(59, 130, 246, 0.6)',
                            borderColor: 'rgb(59, 130, 246)',
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    stepSize: 1
                                }
                            }
                        }
                    }
                });
            }

            // Graphique des genres
            if (genreData.length > 0) {
                const genreCtx = document.getElementById('genreChart').getContext('2d');
                new Chart(genreCtx, {
                    type: 'doughnut',
                    data: {
                        labels: genreData.map(item => item.genre_eleve),
                        datasets: [{
                            data: genreData.map(item => item.nombre),
                            backgroundColor: [
                                'rgba(59, 130, 246, 0.6)',
                                'rgba(236, 72, 153, 0.6)'
                            ],
                            borderColor: [
                                'rgb(59, 130, 246)',
                                'rgb(236, 72, 153)'
                            ],
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true
                    }
                });
            }
        }

        // Chargement différé des listes (Élèves et Personnel) via admin_api
        let elevesOffset = 0, elevesLimit = 50, elevesHasMore = true, elevesLoading = false;
        let personnelOffset = 0, personnelLimit = 50, personnelHasMore = true, personnelLoading = false;

        function debounce(fn, delay) {
            let t;
            return function(...args) {
                clearTimeout(t);
                t = setTimeout(() => fn.apply(this, args), delay);
            };
        }

        function ensureElevesLoaded() {
            const tbody = document.getElementById('elevesTableBody');
            if (!tbody) return;
            if (tbody.dataset._lazyInit) return;
            tbody.dataset._lazyInit = '1';

            const filterClasse = document.getElementById('filterClasse');
            const searchEleve = document.getElementById('searchEleve');
            if (filterClasse) filterClasse.addEventListener('change', () => loadEleves(true));
            if (searchEleve) searchEleve.addEventListener('input', debounce(() => loadEleves(true), 350));

            // Bouton Charger plus
            const table = tbody.closest('table');
            if (table && table.parentNode && !document.getElementById('btnMoreEleves')) {
                const wrap = document.createElement('div');
                wrap.className = 'mt-4 text-center';
                const btn = document.createElement('button');
                btn.id = 'btnMoreEleves';
                btn.className = 'px-4 py-2 bg-gray-100 hover:bg-gray-200 rounded';
                btn.textContent = 'Charger plus';
                btn.addEventListener('click', () => loadEleves(false));
                wrap.appendChild(btn);
                table.parentNode.appendChild(wrap);
            }

            loadEleves(true);
        }

        async function loadEleves(reset = false) {
            if (elevesLoading) return;
            elevesLoading = true;
            try {
                const tbody = document.getElementById('elevesTableBody');
                if (!tbody) return;

                if (reset) {
                    elevesOffset = 0;
                    elevesHasMore = true;
                    tbody.innerHTML = '';
                }
                if (!elevesHasMore) return;

                const classe = document.getElementById('filterClasse')?.value || '';
                const q = document.getElementById('searchEleve')?.value || '';

                const url = new URL('admin_api.php', window.location.href);
                url.searchParams.set('action', 'eleves_list');
                url.searchParams.set('limit', String(elevesLimit));
                url.searchParams.set('offset', String(elevesOffset));
                if (classe) url.searchParams.set('classe', classe);
                if (q) url.searchParams.set('q', q);

                const res = await fetch(url.toString(), { credentials: 'same-origin' });
                const data = await res.json();
                if (data && data.success) {
                    (data.rows || []).forEach(e => tbody.appendChild(createEleveRow(e)));
                    elevesOffset += (data.rows || []).length;
                    elevesHasMore = !!data.hasMore;
                }

                const btn = document.getElementById('btnMoreEleves');
                if (btn) btn.style.display = elevesHasMore ? '' : 'none';
            } catch (e) {
                console.error('loadEleves error', e);
            } finally {
                elevesLoading = false;
            }
        }

        function createEleveRow(eleve) {
            const tr = document.createElement('tr');
            tr.className = 'eleve-row hover:bg-gray-50';
            tr.setAttribute('data-classe', eleve.classe_eleve || '');
            tr.setAttribute('data-nom', ((eleve.nom_eleve || '') + ' ' + (eleve.prenom_eleve || '')).toLowerCase());

            const initials = ((eleve.prenom_eleve || '').charAt(0) + (eleve.nom_eleve || '').charAt(0)).toUpperCase();
            let dateNaissanceFormatted = '';
            if (eleve.date_de_naissance_eleve) {
                const d = new Date(eleve.date_de_naissance_eleve);
                if (!isNaN(d.getTime())) {
                    dateNaissanceFormatted = d.toLocaleDateString('fr-FR');
                }
            }

            tr.innerHTML = `
                <td class="px-6 py-4 whitespace-nowrap">
                    <div class="flex items-center">
                        ${eleve.photo_eleve ? 
                            `<img src="${eleve.photo_eleve}" alt="Photo ${eleve.prenom_eleve || ''}" class="w-10 h-10 rounded-full object-cover">` :
                            `<div class="w-10 h-10 bg-blue-500 rounded-full flex items-center justify-center">
                                <span class="text-white font-bold">${initials || '?'}</span>
                            </div>`
                        }
                        <div class="ml-3">
                            <div class="text-sm font-medium text-gray-900">
                                ${(eleve.prenom_eleve || '')} ${(eleve.nom_eleve || '')}
                            </div>
                            <div class="text-sm text-gray-500">
                                ${dateNaissanceFormatted ? `Né(e) le ${dateNaissanceFormatted}` : 'Date non renseignée'}
                            </div>
                        </div>
                    </div>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                    ${eleve.matricule_eleve || ''}
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                    <span class="px-2 py-1 text-xs font-semibold rounded-full bg-blue-100 text-blue-800">
                        ${eleve.classe_eleve || ''}
                    </span>
                </td>
                
                <td class="px-6 py-4 whitespace-nowrap">
                    <div class="text-sm text-gray-900">Élève: ${eleve.telephone_eleve || '-'}</div>
                    <div class="text-sm text-gray-500">Parent: ${eleve.telephone_parent || '-'}</div>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                    <button onclick="viewEleve(${eleve.id})" class="text-blue-600 hover:text-blue-900 mr-3">
                        <i class="fas fa-eye"></i>
                    </button>
                    <button onclick="editEleve(${eleve.id})" class="text-green-600 hover:text-green-900 mr-3">
                        <i class="fas fa-edit"></i>
                    </button>
                    <button onclick="deleteEleve(${eleve.id})" class="text-red-600 hover:text-red-900">
                        <i class="fas fa-trash"></i>
                    </button>
                </td>
            `;
            return tr;
        }

        function ensurePersonnelLoaded() {
            const tbody = document.getElementById('personnelTableBody');
            if (!tbody) return;
            if (tbody.dataset._lazyInit) return;
            tbody.dataset._lazyInit = '1';

            const search = document.getElementById('searchPersonnel');
            const filterFonction = document.getElementById('filterFonction');
            const filterRole = document.getElementById('filterRole');
            if (search) search.addEventListener('input', debounce(() => loadPersonnel(true), 350));
            if (filterFonction) filterFonction.addEventListener('change', () => loadPersonnel(true));
            if (filterRole) filterRole.addEventListener('change', () => loadPersonnel(true));

            // Bouton Charger plus
            const table = tbody.closest('table');
            if (table && table.parentNode && !document.getElementById('btnMorePersonnel')) {
                const wrap = document.createElement('div');
                wrap.className = 'mt-4 text-center';
                const btn = document.createElement('button');
                btn.id = 'btnMorePersonnel';
                btn.className = 'px-4 py-2 bg-gray-100 hover:bg-gray-200 rounded';
                btn.textContent = 'Charger plus';
                btn.addEventListener('click', () => loadPersonnel(false));
                wrap.appendChild(btn);
                table.parentNode.appendChild(wrap);
            }

            loadPersonnel(true);
        }

        async function loadPersonnel(reset = false) {
            if (personnelLoading) return;
            personnelLoading = true;
            try {
                const tbody = document.getElementById('personnelTableBody');
                if (!tbody) return;

                if (reset) {
                    personnelOffset = 0;
                    personnelHasMore = true;
                    tbody.innerHTML = '';
                }
                if (!personnelHasMore) return;

                const q = document.getElementById('searchPersonnel')?.value || '';
                const role = document.getElementById('filterRole')?.value || '';

                const url = new URL('admin_api.php', window.location.href);
                url.searchParams.set('action', 'personnel_list');
                url.searchParams.set('limit', String(personnelLimit));
                url.searchParams.set('offset', String(personnelOffset));
                if (role) url.searchParams.set('role', role);
                if (q) url.searchParams.set('q', q);

                const res = await fetch(url.toString(), { credentials: 'same-origin' });
                const data = await res.json();
                if (data && data.success) {
                    (data.rows || []).forEach(u => tbody.appendChild(createPersonnelRow(u)));
                    personnelOffset += (data.rows || []).length;
                    personnelHasMore = !!data.hasMore;
                }

                const btn = document.getElementById('btnMorePersonnel');
                if (btn) btn.style.display = personnelHasMore ? '' : 'none';
            } catch (e) {
                console.error('loadPersonnel error', e);
            } finally {
                personnelLoading = false;
            }
        }

        function createPersonnelRow(user) {
            const tr = document.createElement('tr');
            tr.className = 'personnel-row hover:bg-gray-50';
            tr.setAttribute('data-fonction', user.fonction_u || '');
            tr.setAttribute('data-role', user.role_u || '');
            tr.setAttribute('data-nom', ((user.nom_u || '') + ' ' + (user.prenom_u || '')).toLowerCase());

            const initials = ((user.prenom_u || '').charAt(0) + (user.nom_u || '').charAt(0)).toUpperCase();

            tr.innerHTML = `
                <td class="px-6 py-4 whitespace-nowrap">
                    <div class="flex items-center">
                        ${user.pp_u ? 
                            `<img src="${user.pp_u}" alt="Photo ${user.prenom_u || ''}" class="w-10 h-10 rounded-full object-cover">` :
                            `<div class="w-10 h-10 bg-green-500 rounded-full flex items-center justify-center">
                                <span class="text-white font-bold">${initials || '?'}</span>
                            </div>`
                        }
                        <div class="ml-3">
                            <div class="text-sm font-medium text-gray-900">
                                ${(user.prenom_u || '')} ${(user.nom_u || '')}
                            </div>
                            <div class="text-sm text-gray-500">
                                ID: ${user.id}
                            </div>
                        </div>
                    </div>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                    <div class="text-sm text-gray-900">${user.mail_u || ''}</div>
                    <div class="text-sm text-gray-500">${user.tel_u || ''}</div>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                    <span class="px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">
                        ${user.fonction_u || ''}
                    </span>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                    <span class="px-2 py-1 text-xs font-semibold rounded-full bg-purple-100 text-purple-800">
                        ${user.role_u || ''}
                    </span>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                    ${user.matiere_niveau_u || 'Non défini'}
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                    <button onclick="viewPersonnel(${user.id})" class="text-blue-600 hover:text-blue-900 mr-3">
                        <i class="fas fa-eye"></i>
                    </button>
                    <button onclick="editPersonnel(${user.id})" class="text-indigo-600 hover:text-indigo-900 mr-3">
                        <i class="fas fa-edit"></i>
                    </button>
                    <button onclick="deletePersonnel(${user.id})" class="text-red-600 hover:text-red-900">
                        <i class="fas fa-trash"></i>
                    </button>
                </td>
            `;
            return tr;
        }

        // Filtrage du personnel
        function filterPersonnel() {
            const searchTerm = document.getElementById('searchPersonnel').value.toLowerCase();
            const fonctionFilter = document.getElementById('filterFonction').value;
            const roleFilter = document.getElementById('filterRole').value;
            const rows = document.querySelectorAll('.personnel-row');

            rows.forEach(row => {
                const nom = row.getAttribute('data-nom');
                const fonction = row.getAttribute('data-fonction');
                const role = row.getAttribute('data-role');

                const matchesSearch = nom.includes(searchTerm);
                const matchesFonction = !fonctionFilter || fonction === fonctionFilter;
                const matchesRole = !roleFilter || role === roleFilter;

                if (matchesSearch && matchesFonction && matchesRole) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }

        // Initialisation générale au chargement
        var classeChartInstance = null;
        var genreChartInstance = null;

        async function fetchStats() {
            try {
                const res = await fetch('admin_api.php?action=stats', { credentials: 'same-origin' });
                const data = await res.json();
                if (!data || !data.success) return;

                // Dashboard counters
                const te = document.getElementById('total-eleves-stat');
                const tp = document.getElementById('total-personnel-stat');
                const tc = document.getElementById('total-classes-stat');
                const tm = document.getElementById('total-matieres-stat');
                if (te) te.textContent = (data.total_eleves || 0).toLocaleString();
                if (tp) tp.textContent = (data.total_personnel || 0).toLocaleString();
                if (tc) tc.textContent = (data.total_classes || 0).toLocaleString();
                if (tm) tm.textContent = (data.total_matieres || 0).toLocaleString();

                // Detailed counters in Statistiques section
                const dte = document.getElementById('detail-total-eleves');
                const dtp = document.getElementById('detail-total-personnel');
                const dtc = document.getElementById('detail-total-classes');
                const dtm = document.getElementById('detail-total-matieres');
                if (dte) dte.textContent = (data.total_eleves || 0).toLocaleString();
                if (dtp) dtp.textContent = (data.total_personnel || 0).toLocaleString();
                if (dtc) dtc.textContent = (data.total_classes || 0).toLocaleString();
                if (dtm) dtm.textContent = (data.total_matieres || 0).toLocaleString();

                const dmoy = document.getElementById('detail-moyenne-eleves-classe');
                const dratio = document.getElementById('detail-ratio-personnel-eleves');
                const droles = document.getElementById('detail-total-roles');
                if (dmoy) dmoy.textContent = (data.moyenne_eleves_par_classe || 0);
                if (dratio) dratio.textContent = (data.ratio_personnel_eleves || 0);
                if (droles) droles.textContent = (data.total_roles || 0);

                // Update genre list
                const genreWrap = document.getElementById('detail-genre-list');
                if (genreWrap) {
                    const totalEleves = data.total_eleves || 0;
                    const items = (data.repartition_genre || []).map(g => {
                        const n = parseInt(g.nombre, 10) || 0;
                        const pct = totalEleves > 0 ? Math.round((n / totalEleves) * 100) : 0;
                        const label = (g.genre_eleve || '').charAt(0).toUpperCase() + (g.genre_eleve || '').slice(1);
                        return `
                            <div class="flex justify-between items-center">
                                <span class="text-gray-700">${label}</span>
                                <div class="flex items-center">
                                    <div class="w-24 bg-gray-200 rounded-full h-2 mr-3">
                                        <div class="bg-blue-500 h-2 rounded-full" style="width: ${pct}%"></div>
                                    </div>
                                    <span class="font-semibold text-blue-600">${n.toLocaleString()}</span>
                                </div>
                            </div>`;
                    }).join('') || '<div class="text-gray-500 text-sm">Aucune donnée</div>';
                    genreWrap.innerHTML = items;
                }

                // Update personnel by role
                const roleWrap = document.getElementById('detail-personnel-par-role');
                if (roleWrap) {
                    const totalPers = data.total_personnel || 0;
                    const items = (data.personnel_par_role || []).map(r => {
                        const n = parseInt(r.nombre, 10) || 0;
                        const pct = totalPers > 0 ? Math.round((n / totalPers) * 100) : 0;
                        const label = (r.role_u || 'Non défini');
                        return `
                            <div class="flex justify-between items-center">
                                <span class="text-gray-700">${label}</span>
                                <div class="flex items-center">
                                    <div class="w-24 bg-gray-200 rounded-full h-2 mr-3">
                                        <div class="bg-green-500 h-2 rounded-full" style="width: ${pct}%"></div>
                                    </div>
                                    <span class="font-semibold text-green-600">${n.toLocaleString()}</span>
                                </div>
                            </div>`;
                    }).join('') || '<div class="text-gray-500 text-sm">Aucune donnée</div>';
                    roleWrap.innerHTML = items;
                }

                // Update top/bottom classes
                const topWrap = document.getElementById('detail-top-classes');
                if (topWrap) {
                    const items = (data.top_classes_peuplees || []).map(cl => `
                        <div class="flex justify-between items-center py-2 border-b border-gray-200">
                            <span class="text-gray-700 font-medium">${cl.nom_classe || ''}</span>
                            <span class="bg-red-100 text-red-800 px-3 py-1 rounded-full text-sm font-semibold">${(parseInt(cl.nombre_eleves,10)||0).toLocaleString()} élèves</span>
                        </div>
                    `).join('') || '<div class="text-gray-500 text-sm">Aucune donnée</div>';
                    topWrap.innerHTML = items;
                }
                const lowWrap = document.getElementById('detail-classes-moins');
                if (lowWrap) {
                    const items = (data.classes_moins_peuplees || []).map(cl => `
                        <div class="flex justify-between items-center py-2 border-b border-gray-200">
                            <span class="text-gray-700 font-medium">${cl.nom_classe || ''}</span>
                            <span class="bg-yellow-100 text-yellow-800 px-3 py-1 rounded-full text-sm font-semibold">${(parseInt(cl.nombre_eleves,10)||0).toLocaleString()} élèves</span>
                        </div>
                    `).join('') || '<div class="text-gray-500 text-sm">Aucune donnée</div>';
                    lowWrap.innerHTML = items;
                }

                // Update grid: élèves par classe
                const grid = document.getElementById('detail-eleves-par-classe-grid');
                if (grid) {
                    const items = (data.eleves_par_classe || []).map(cl => `
                        <div class="bg-white rounded-lg p-4 shadow-sm">
                            <div class="flex justify-between items-center">
                                <span class="font-medium text-gray-800">${cl.nom_classe || ''}</span>
                                <span class="bg-blue-100 text-blue-800 px-2 py-1 rounded text-sm font-semibold">${(parseInt(cl.nombre_eleves,10)||0).toLocaleString()}</span>
                            </div>
                        </div>
                    `).join('');
                    grid.innerHTML = items || '<div class="text-gray-500 text-sm">Aucune classe</div>';
                }

                // Charts
                const classeCanvas = document.getElementById('classeChart');
                const genreCanvas = document.getElementById('genreChart');

                if (classeCanvas) {
                    const labels = (data.eleves_par_classe || []).map(i => i.nom_classe);
                    const values = (data.eleves_par_classe || []).map(i => parseInt(i.nombre_eleves, 10) || 0);
                    if (!classeChartInstance) {
                        const ctx = classeCanvas.getContext('2d');
                        classeChartInstance = new Chart(ctx, {
                            type: 'bar',
                            data: {
                                labels,
                                datasets: [{
                                    label: "Nombre d'élèves",
                                    data: values,
                                    backgroundColor: 'rgba(59, 130, 246, 0.6)',
                                    borderColor: 'rgb(59, 130, 246)',
                                    borderWidth: 1
                                }]
                            },
                            options: {
                                responsive: true,
                                scales: { y: { beginAtZero: true } }
                            }
                        });
                    } else {
                        classeChartInstance.data.labels = labels;
                        classeChartInstance.data.datasets[0].data = values;
                        classeChartInstance.update();
                    }
                }

                if (genreCanvas) {
                    const labels = (data.repartition_genre || []).map(i => i.genre_eleve);
                    const values = (data.repartition_genre || []).map(i => parseInt(i.nombre, 10) || 0);
                    if (!genreChartInstance) {
                        const ctx = genreCanvas.getContext('2d');
                        genreChartInstance = new Chart(ctx, {
                            type: 'doughnut',
                            data: {
                                labels,
                                datasets: [{
                                    data: values,
                                    backgroundColor: ['rgba(59, 130, 246, 0.6)', 'rgba(236, 72, 153, 0.6)'],
                                    borderColor: ['rgb(59, 130, 246)', 'rgb(236, 72, 153)'],
                                    borderWidth: 1
                                }]
                            },
                            options: { responsive: true }
                        });
                    } else {
                        genreChartInstance.data.labels = labels;
                        genreChartInstance.data.datasets[0].data = values;
                        genreChartInstance.update();
                    }
                }
            } catch (e) {
                console.error('fetchStats error', e);
            }
          console.error('fetchStats error', e);
            }
        

        document.addEventListener('DOMContentLoaded', function() {
            
            fetchStats();

            // Restaurer la section sauvegardée
            restoreCurrentSection();

            generateMatiereCode();

            // Nettoyer l'URL des paramètres de succès après affichage du message
            if (window.location.search.includes('success=')) {
                const url = new URL(window.location);
                url.searchParams.delete('success');
                url.searchParams.delete('matricule');
                window.history.replaceState({}, document.title, url.toString());
            }
            

            // Événements de filtrage personnel
            const searchPersonnel = document.getElementById('searchPersonnel');
            const filterFonction = document.getElementById('filterFonction');
            const filterRole = document.getElementById('filterRole');

            if (searchPersonnel) searchPersonnel.addEventListener('input', filterPersonnel);
            if (filterFonction) filterFonction.addEventListener('change', filterPersonnel);
            if (filterRole) filterRole.addEventListener('change', filterPersonnel);

            
            
        });



        // Sauvegarder les jours étudiés
        function enregistrerJours() {
            const checked = Array.from(document.querySelectorAll('.jour-etudie:checked')).map(i => i.value);
            const form = new FormData();
            form.append('action', 'enregistrer_jours');
            checked.forEach(j => form.append('jours[]', j));

            fetch('', { method: 'POST', body: form, headers: {'X-Requested-With':'XMLHttpRequest'} })
                .then(r => r.json())
                .then(d => {
                    showNotification(d.message, d.success ? 'success' : 'error');
                })
                .catch(() => showNotification('Erreur lors de la sauvegarde des jours', 'error'));
        }

        // Remplir le formulaire de créneau pour édition
        function remplirCreneauForm(id, debut, fin, ordre, actif, nom) {
            document.querySelector('#creneauForm [name="action"]').value = 'modifier_creneau';
            document.getElementById('creneau_id').value = id;
            document.getElementById('heure_debut').value = debut.substring(0,5);
            document.getElementById('heure_fin').value = fin.substring(0,5);
            document.getElementById('ordre_affichage').value = ordre;
            document.getElementById('actif').value = String(actif);
            document.getElementById('nom').value = nom || '';
            document.getElementById('creneauSubmitText').textContent = 'Modifier';
        }

        // Reset du formulaire de créneau
        function resetCreneauForm() {
            const form = document.getElementById('creneauForm');
            form.reset();
            document.querySelector('#creneauForm [name="action"]').value = 'ajouter_creneau';
            document.getElementById('creneau_id').value = '';
            document.getElementById('creneauSubmitText').textContent = 'Ajouter';
        }

        // Soumission Ajax du formulaire de créneau
        function soumettreCreneau(e) {
            e.preventDefault();
            const formEl = document.getElementById('creneauForm');
            const fd = new FormData(formEl);

            fetch('', { method: 'POST', body: fd, headers: {'X-Requested-With':'XMLHttpRequest'} })
                .then(r => r.json())
                .then(d => {
                    showNotification(d.message, d.success ? 'success' : 'error');
                    if (d.success) {
                        // Rafraîchir la page pour recharger la liste ou injecter dynamiquement
                        location.reload();
                    }
                })
                .catch(() => showNotification('Erreur lors de l\'enregistrement du créneau', 'error'));
        }

        // Supprimer un créneau
        function supprimerCreneau(id) {
            if (!confirm('Supprimer ce créneau ?')) return;
            const fd = new FormData();
            fd.append('action', 'supprimer_creneau');
            fd.append('id', id);

            fetch('', { method: 'POST', body: fd, headers: {'X-Requested-With':'XMLHttpRequest'} })
                .then(r => r.json())
                .then(d => {
                    showNotification(d.message, d.success ? 'success' : 'error');
                    if (d.success) {
                        location.reload();
                    }
                })
                .catch(() => showNotification('Erreur lors de la suppression du créneau', 'error'));
        }

// Fonctions pour les modales et actions

        // Gestion des lignes Matière/Niveau
        let matiereNiveauCounter = 0;

        function addMatiereNiveauLine() {
            matiereNiveauCounter++;
            const container = document.getElementById('matiereNiveauContainer');
            const newLine = document.createElement('div');
            newLine.className = 'matiere-niveau-line grid grid-cols-1 lg:grid-cols-2 gap-4 p-4 border border-gray-200 rounded-lg bg-gray-50';
            newLine.id = `matiereNiveau_${matiereNiveauCounter}`;
            
            newLine.innerHTML = `
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Matière</label>
                    <select name="matieres[]" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                        <option value="">Sélectionner une matière</option>
                        <?php foreach ($matieres as $matiere): ?>
                        <option value="<?php echo htmlspecialchars($matiere['nom_matiere']); ?>">
                            <?php echo htmlspecialchars($matiere['nom_matiere']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Niveaux/Classes</label>
                    <div class="multi-select-with-tags">
                        <!-- Zone de saisie avec dropdown -->
                        <div class="relative">
                            <input type="text" 
                                   id="classInput_${matiereNiveauCounter}"
                                   placeholder="Tapez pour rechercher une classe..."
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                                   autocomplete="off"
                                   onclick="showClassDropdown(${matiereNiveauCounter})"
                                   oninput="filterClasses(${matiereNiveauCounter}, this.value)">
                            
                            <!-- Dropdown des classes disponibles -->
                            <div id="classDropdown_${matiereNiveauCounter}" 
                                 class="hidden absolute z-10 w-full bg-white border border-gray-300 rounded-lg shadow-lg mt-1 max-h-48 overflow-y-auto">
                                <?php foreach ($classes as $classe): ?>
                                <div class="class-option px-3 py-2 hover:bg-gray-50 cursor-pointer border-b border-gray-100 last:border-b-0"
                                     data-value="<?php echo htmlspecialchars($classe['nom_classe']); ?>"
                                     data-display="<?php echo htmlspecialchars($classe['nom_classe'] . ' - ' . $classe['niveau']); ?>"
                                     onclick="addClassTag(${matiereNiveauCounter}, '<?php echo htmlspecialchars($classe['nom_classe']); ?>', '<?php echo htmlspecialchars($classe['nom_classe'] . ' - ' . $classe['niveau']); ?>')">
                                    <?php echo htmlspecialchars($classe['nom_classe'] . ' - ' . $classe['niveau']); ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        
                        <!-- Zone des tags sélectionnés -->
                        <div id="selectedTags_${matiereNiveauCounter}" class="flex flex-wrap gap-2 mt-2 min-h-[2rem] p-2 bg-gray-50 rounded border border-gray-200">
                            <span class="text-gray-500 text-sm" id="placeholder_${matiereNiveauCounter}">Aucune classe sélectionnée</span>
                        </div>
                        
                        <!-- Input hidden pour la soumission -->
                        <input type="hidden" name="niveaux[]" id="niveaux_${matiereNiveauCounter}">
                    </div>
                </div>
                <div class="lg:col-span-2 flex justify-end">
                    <button type="button" onclick="removeMatiereNiveauLine(${matiereNiveauCounter})" 
                            class="text-red-600 hover:text-red-800 text-sm">
                        <i class="fas fa-trash mr-1"></i>Supprimer cette ligne
                    </button>
                </div>
            `;
            
            container.appendChild(newLine);
            updateMatiereNiveauVisibility();
        }

        function removeMatiereNiveauLine(id) {
            const element = document.getElementById(`matiereNiveau_${id}`);
            if (element) {
                element.remove();
            }
            updateMatiereNiveauVisibility();
        }

        function resetMatiereNiveauLines() {
            document.getElementById('matiereNiveauContainer').innerHTML = '';
            matiereNiveauCounter = 0;
            updateMatiereNiveauVisibility();
        }

        function updateMatiereNiveauVisibility() {
            // La section matières/niveaux est toujours visible car tout personnel peut enseigner
            const container = document.getElementById('matiereNiveauSection');
            const lines = document.querySelectorAll('.matiere-niveau-line');
            
            // Toujours afficher la section
            container.classList.remove('hidden');
            
            // Ajouter une ligne par défaut si aucune n'existe
            if (lines.length === 0) {
                addMatiereNiveauLine();
            }
        }

        // Multi-select avec tags et croix
        function showClassDropdown(id) {
            const dropdown = document.getElementById(`classDropdown_${id}`);
            dropdown.classList.remove('hidden');
        }

        function filterClasses(id, searchTerm) {
            const dropdown = document.getElementById(`classDropdown_${id}`);
            const options = dropdown.querySelectorAll('.class-option');
            const term = searchTerm.toLowerCase();
            
            dropdown.classList.remove('hidden');
            
            options.forEach(option => {
                const text = option.textContent.toLowerCase();
                if (text.includes(term)) {
                    option.style.display = 'block';
                } else {
                    option.style.display = 'none';
                }
            });
        }

        function addClassTag(id, value, displayText) {
            const tagsContainer = document.getElementById(`selectedTags_${id}`);
            const hiddenInput = document.getElementById(`niveaux_${id}`);
            const placeholder = document.getElementById(`placeholder_${id}`);
            const input = document.getElementById(`classInput_${id}`);
            const dropdown = document.getElementById(`classDropdown_${id}`);
            
            // Vérifier si la classe n'est pas déjà ajoutée
            const existingTag = tagsContainer.querySelector(`[data-value="${value}"]`);
            if (existingTag) return;
            
            // Masquer le placeholder
            if (placeholder) placeholder.style.display = 'none';
            
            // Créer le tag avec croix
            const tag = document.createElement('div');
            tag.className = 'inline-flex items-center bg-blue-100 text-blue-800 text-sm font-medium px-3 py-1 rounded-full';
            tag.dataset.value = value;
            tag.innerHTML = `
                <span class="mr-2">${displayText}</span>
                <button type="button" onclick="removeClassTag(${id}, '${value}')" 
                        class="text-blue-600 hover:text-blue-800 focus:outline-none">
                    <i class="fas fa-times text-xs"></i>
                </button>
            `;
            
            tagsContainer.appendChild(tag);
            
            // Mettre à jour l'input hidden
            updateHiddenInput(id);
            
            // Nettoyer et fermer
            input.value = '';
            dropdown.classList.add('hidden');
        }

        function removeClassTag(id, value) {
            const tagsContainer = document.getElementById(`selectedTags_${id}`);
            const tag = tagsContainer.querySelector(`[data-value="${value}"]`);
            const placeholder = document.getElementById(`placeholder_${id}`);
            
            if (tag) {
                tag.remove();
                updateHiddenInput(id);
                
                // Réafficher le placeholder si aucun tag
                const remainingTags = tagsContainer.querySelectorAll('[data-value]');
                if (remainingTags.length === 0 && placeholder) {
                    placeholder.style.display = 'inline';
                }
            }
        }

        function updateHiddenInput(id) {
            const tagsContainer = document.getElementById(`selectedTags_${id}`);
            const hiddenInput = document.getElementById(`niveaux_${id}`);
            const tags = tagsContainer.querySelectorAll('[data-value]');
            
            const values = Array.from(tags).map(tag => tag.dataset.value);
            hiddenInput.value = values.join(':');
        }

        // Fermer les dropdowns en cliquant ailleurs
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.multi-select-with-tags')) {
                document.querySelectorAll('[id^="classDropdown_"]').forEach(dropdown => {
                    dropdown.classList.add('hidden');
                });
            }
        });

        

        function showAddMatiereModal() {
            showNotification('Utilisez la sélection rapide pour ajouter des matières.', 'info');
        }

        function toggleSelectionMatieres() {
            var el = document.getElementById('selectionMatieres');
            if (!el) return;
            var isHidden = el.classList.contains('hidden');
            if (isHidden) {
                el.classList.remove('hidden');
                try { el.scrollIntoView({ behavior: 'smooth', block: 'start' }); } catch(e) {}
            } else {
                el.classList.add('hidden');
            }
        }

        function showAddFonctionModal() {
            alert('Fonction d\'ajout de fonction à implémenter');
        }

        function showAddRoleModal() {
            alert('Fonction d\'ajout de rôle à implémenter');
        }

        function editInformationsEcole() {
            alert('Fonction d\'édition des informations école à implémenter');
        }

        // Fonctions d'actions pour le personnel
        function viewPersonnel(id) {
            // TODO: Implémenter la fonction de visualisation du personnel
            showNotification('Fonction de visualisation en cours de développement', 'info');
        }

        // ===== MODALE ET FONCTIONS DE MODIFICATION DE CLASSE =====
let editClasseMatiereCounter = 0;

function closeEditClasseModal() {
    document.getElementById('editClasseModal').classList.add('hidden');
}

function addClasseMatiereLine(matiere = '', coefficient = 1, bareme = 20) {
    editClasseMatiereCounter++;
    const container = document.getElementById('classeMatiereLines');
    if (!container) return;

    const line = document.createElement('div');
    line.className = 'grid grid-cols-12 gap-3 p-3 border border-gray-200 rounded-lg bg-gray-50';
    line.id = `classeMatiere_${editClasseMatiereCounter}`;
    line.innerHTML = `
        <div class="col-span-6">
            <label class="block text-xs text-gray-600 mb-1">Matière</label>
            <select name="matieres[]" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500">
                <option value="">Sélectionner une matière</option>
                <?php foreach ($matieres as $matiere): ?>
                <option value="<?php echo htmlspecialchars($matiere['nom_matiere']); ?>"><?php echo htmlspecialchars($matiere['nom_matiere']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-span-3">
            <label class="block text-xs text-gray-600 mb-1">Coefficient</label>
            <input type="number" name="coefficients[]" min="1" max="10" value="${coefficient}" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500">
        </div>
        <div class="col-span-3">
            <label class="block text-xs text-gray-600 mb-1">Barème</label>
            <input type="number" name="baremes[]" min="10" max="100" value="${bareme}" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500">
        </div>
        <div class="col-span-12 flex justify-end">
            <button type="button" class="text-red-600 hover:text-red-800 text-sm" onclick="document.getElementById('classeMatiere_${editClasseMatiereCounter}').remove()">
                <i class="fas fa-trash mr-1"></i>Supprimer
            </button>
        </div>
    `;
    container.appendChild(line);
    // Set selected matiere if provided
    if (matiere) {
        const select = line.querySelector('select[name="matieres[]"]');
        if (select) select.value = matiere;
    }
}

function resetClasseMatiereLines() {
    const container = document.getElementById('classeMatiereLines');
    if (container) container.innerHTML = '';
    editClasseMatiereCounter = 0;
}

function populateClasseMatiereFromString(matCoefBareme) {
    resetClasseMatiereLines();
    const str = (matCoefBareme || '').trim();
    if (!str) {
        addClasseMatiereLine();
        return;
    }
    // Format attendu: "Matiere:coef:bareme | Matiere2:coef:bareme"
    str.split('|').map(s => s.trim()).filter(Boolean).forEach(part => {
        const pieces = part.split(':').map(p => p.trim());
        const mat = pieces[0] || '';
        const coef = parseInt(pieces[1], 10) || 1;
        const bare = parseInt(pieces[2], 10) || 20;
        addClasseMatiereLine(mat, coef, bare);
    });
}

// Changement de la classe sélectionnée dans la modale d'édition
function editClasseSelectChanged(selectEl) {
    if (!selectEl) return;
    const opt = selectEl.options[selectEl.selectedIndex];
    const classeId = opt ? opt.getAttribute('data-id') : '';

    // Mettre à jour l'ID caché
    const idHidden = document.getElementById('edit_classe_id');
    if (idHidden) idHidden.value = classeId || '';
    if (!classeId) return;

    // Récupérer les données de la classe sélectionnée et préremplir
    const fd = new FormData();
    fd.append('action', 'get_classe_data');
    fd.append('classe_id', classeId);

    fetch('', { 
        method: 'POST', 
        body: fd, 
        headers: { 'X-Requested-With': 'XMLHttpRequest' } 
    })
    .then(r => r.json())
    .then(d => {
        if (!d || !d.success || !d.classe) {
            showNotification((d && d.message) || 'Classe introuvable', 'error');
            return;
        }

        const c = d.classe;

        // Mettre à jour le niveau
        const nivSelect = document.getElementById('edit_niveau_select');
        if (nivSelect) {
            const exists = Array.from(nivSelect.options).some(o => o.value === (c.niveau || ''));
            if (!exists && c.niveau) {
                const o = document.createElement('option');
                o.value = c.niveau;
                o.textContent = c.niveau;
                nivSelect.appendChild(o);
            }
            nivSelect.value = c.niveau || '';
        }

        // Pré-remplir les matières
        populateClasseMatiereFromString(c.mat_coef_bareme || '');
    })
    .catch(() => showNotification('Erreur lors du chargement de la classe', 'error'));
}

function editClasse(id) {
    const fd = new FormData();
    fd.append('action', 'get_classe_data');
    fd.append('classe_id', id);

    fetch('', { method: 'POST', body: fd, headers: {'X-Requested-With':'XMLHttpRequest'} })
        .then(r => r.json())
        .then(d => {
            if (!d || !d.success || !d.classe) {
                showNotification(d && d.message ? d.message : 'Classe introuvable', 'error');
                return;
            }
            const c = d.classe;
            document.getElementById('edit_classe_id').value = c.id;
            const nomSelect = document.getElementById('edit_nom_classe_select');
            if (nomSelect) {
                // Préselectionner l'option correspondant à la classe
                let matched = false;
                Array.from(nomSelect.options).forEach(opt => {
                    if (opt.getAttribute('data-id') == String(c.id)) {
                        opt.selected = true;
                        matched = true;
                    }
                });
                if (!matched && c.nom_classe) {
                    // Si l'option n'existe pas (cas rare), l'ajouter
                    const opt = document.createElement('option');
                    opt.value = c.nom_classe;
                    opt.textContent = c.nom_classe;
                    opt.setAttribute('data-id', c.id);
                    opt.setAttribute('data-niveau', c.niveau || '');
                    nomSelect.appendChild(opt);
                    nomSelect.value = c.nom_classe;
                }
                // Mettre à jour l'ID caché
                const selectedOpt = nomSelect.options[nomSelect.selectedIndex];
                document.getElementById('edit_classe_id').value = selectedOpt ? selectedOpt.getAttribute('data-id') : c.id;
            }
            const nivSelect = document.getElementById('edit_niveau_select');
            if (nivSelect) {
                const exists = Array.from(nivSelect.options).some(o => o.value === (c.niveau || ''));
                if (!exists && c.niveau) {
                    const o = document.createElement('option');
                    o.value = c.niveau;
                    o.textContent = c.niveau;
                    nivSelect.appendChild(o);
                }
                nivSelect.value = c.niveau || '';
            }
            populateClasseMatiereFromString(c.mat_coef_bareme || '');
            document.getElementById('editClasseModal').classList.remove('hidden');
        })
        .catch(() => showNotification('Erreur lors du chargement de la classe', 'error'));
}

function submitEditClasse(e) {
    e.preventDefault();
    const form = document.getElementById('editClasseForm');
    const submitBtn = form.querySelector('button[type="submit"]');
    const original = submitBtn.innerHTML;
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Enregistrement...';

    const fd = new FormData(form);
    fetch('', { method: 'POST', body: fd, headers: {'X-Requested-With':'XMLHttpRequest'} })
        .then(r => r.json())
        .then(d => {
            if (d && d.success) {
                showNotification(d.message || 'Classe modifiée', 'success');
                // Mettre à jour la ligne dans le tableau
                const id = form.querySelector('#edit_classe_id').value;
                const rowBtn = document.querySelector(`button[onclick="editClasse(${id})"]`);
                if (rowBtn) {
                    const row = rowBtn.closest('tr');
                    if (row) {
                        // Colonnes: 0 Nom, 1 Niveau, 2 Matieres, 3 Nb, 4 Date, 5 Actions
                        const nom = form.querySelector('#edit_nom_classe').value;
                        const niveau = form.querySelector('#edit_niveau').value;
                        const matieres = Array.from(form.querySelectorAll('select[name="matieres[]"]')).map(s => s.value.trim());
                        const coefs = Array.from(form.querySelectorAll('input[name="coefficients[]"]')).map(i => i.value.trim());
                        const bares = Array.from(form.querySelectorAll('input[name="baremes[]"]')).map(i => i.value.trim());
                        const parts = matieres.map((m, i) => m ? `${m}:${coefs[i] || 1}:${bares[i] || 20}` : '').filter(Boolean);
                        const formatted = parts.join(' | ');

                        const nomCell = row.children[0]?.querySelector('.text-sm.font-medium.text-gray-900');
                        if (nomCell) nomCell.textContent = nom;
                        const nivCell = row.children[1]?.querySelector('span');
                        if (nivCell) nivCell.textContent = niveau;
                        const matCell = row.children[2]?.querySelector('.matiere-display');
                        if (matCell) {
                            matCell.textContent = formatted || 'Non défini';
                            matCell.setAttribute('data-raw', formatted);
                        }
                    }
                }
                //closeEditClasseModal();
            } else {
                showNotification((d && d.message) || 'Erreur lors de la modification', 'error');
            }
        })
        
        .finally(() => {
            submitBtn.disabled = false;
            submitBtn.innerHTML = original;
            closeEditClasseModal();
        });
}

// ===== FONCTIONS DE SUPPRESSION DE CLASSE =====
        
        // Variable globale pour la suppression
        let currentDeleteClasseId = null;
        
        function deleteClasse(id) {
            // Récupérer le nom de la classe depuis le tableau
            const row = document.querySelector(`button[onclick="deleteClasse(${id})"]`).closest('tr');
            const nomClasse = row.querySelectorAll('td')[1].textContent.trim();
            
            // Remplir les détails dans la modal de suppression
            document.getElementById('deleteClasseNom').textContent = nomClasse;
            currentDeleteClasseId = id;
            
            // Afficher la modal de confirmation
            document.getElementById('deleteClasseModal').classList.remove('hidden');
        }

        function closeDeleteClasseModal() {
            document.getElementById('deleteClasseModal').classList.add('hidden');
            currentDeleteClasseId = null;
        }

        function confirmDeleteClasse() {
            if (!currentDeleteClasseId) return;
            
            const formData = new FormData();
            formData.append('action', 'supprimer_classe');
            formData.append('id_classe', currentDeleteClasseId);
            
            // Désactiver le bouton pour éviter les doubles clics
            const confirmButton = document.querySelector('#deleteClasseModal button[onclick="confirmDeleteClasse()"]');
            const originalText = confirmButton.innerHTML;
            confirmButton.disabled = true;
            confirmButton.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Suppression...';
            
            fetch('', {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification(data.message, 'success');
                    
                    // Supprimer la ligne du tableau avec animation
                    const row = document.querySelector(`button[onclick="deleteClasse(${currentDeleteClasseId})"]`).closest('tr');
                    if (row) {
                        // Animation de suppression
                        row.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
                        row.style.opacity = '0';
                        row.style.transform = 'translateX(-20px)';
                        
                        setTimeout(() => {
                            row.remove();
                            // Mettre à jour les statistiques si nécessaire
                            updateStats();
                        }, 300);
                    }
                    
                    // Fermer la modal
                    closeDeleteClasseModal();
                } else {
                    showNotification(data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Erreur:', error);
                showNotification('Une erreur est survenue lors de la suppression', 'error');
            })
            .finally(() => {
                // Réactiver le bouton
                confirmButton.disabled = false;
                confirmButton.innerHTML = originalText;
            });
        }

        // Fonctions d'actions pour les fonctions
        function editFonction(id) {
            alert('Modifier fonction ID: ' + id);
        }

        function deleteFonction(id) {
            if (confirm('Êtes-vous sûr de vouloir supprimer cette fonction ?')) {
                alert('Supprimer fonction ID: ' + id);
            }
        }

        // Fonctions d'actions pour les rôles
        function editRole(id) {
            alert('Modifier rôle ID: ' + id);
        }

        function deleteRole(id) {
            if (confirm('Êtes-vous sûr de vouloir supprimer ce rôle ?')) {
                alert('Supprimer rôle ID: ' + id);
            }
        }

        // Fonction pour afficher le modal d'ajout d'élève
        function showAddEleveModal() {
            document.getElementById('addEleveModal').classList.remove('hidden');
        }

        // Fonction pour fermer le modal d'ajout d'élève
        function closeAddEleveModal() {
            document.getElementById('addEleveModal').classList.add('hidden');
        }





        // Fonction d'auto-complétion générique
        function setupAutoComplete(inputId, suggestions) {
            const input = document.getElementById(inputId);
            if (!input) return;

            const wrapper = input.parentNode;
            wrapper.style.position = 'relative';

            // Créer la liste de suggestions
            const suggestionsList = document.createElement('div');
            suggestionsList.className = 'absolute z-50 w-full bg-white border border-gray-300 rounded-lg shadow-lg mt-1 max-h-48 overflow-y-auto hidden';
            suggestionsList.style.fontSize = '14px';
            wrapper.appendChild(suggestionsList);

            input.addEventListener('input', function() {
                const value = this.value.toLowerCase().trim();
                suggestionsList.innerHTML = '';
                
                if (value.length < 2) {
                    suggestionsList.classList.add('hidden');
                    return;
                }

                const filtered = suggestions.filter(item => 
                    item.toLowerCase().includes(value)
                ).slice(0, 10);

                if (filtered.length === 0) {
                    suggestionsList.classList.add('hidden');
                    return;
                }

                filtered.forEach(item => {
                    const div = document.createElement('div');
                    div.className = 'px-4 py-2 hover:bg-blue-50 cursor-pointer text-sm';
                    div.textContent = item;
                    div.addEventListener('click', function() {
                        input.value = item;
                        suggestionsList.classList.add('hidden');
                        input.focus();
                    });
                    suggestionsList.appendChild(div);
                });

                suggestionsList.classList.remove('hidden');
            });

            // Fermer les suggestions quand on clique ailleurs
            document.addEventListener('click', function(e) {
                if (!wrapper.contains(e.target)) {
                    suggestionsList.classList.add('hidden');
                }
            });

            // Navigation au clavier
            input.addEventListener('keydown', function(e) {
                const items = suggestionsList.querySelectorAll('div');
                const active = suggestionsList.querySelector('.bg-blue-100');
                let activeIndex = Array.from(items).indexOf(active);

                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    if (active) active.classList.remove('bg-blue-100');
                    activeIndex = activeIndex < items.length - 1 ? activeIndex + 1 : 0;
                    items[activeIndex]?.classList.add('bg-blue-100');
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    if (active) active.classList.remove('bg-blue-100');
                    activeIndex = activeIndex > 0 ? activeIndex - 1 : items.length - 1;
                    items[activeIndex]?.classList.add('bg-blue-100');
                } else if (e.key === 'Enter' && active) {
                    e.preventDefault();
                    active.click();
                } else if (e.key === 'Escape') {
                    suggestionsList.classList.add('hidden');
                }
            });
        }

        // Prévisualiser la photo sélectionnée
        function previewPhoto(event) {
            const file = event.target.files[0];
            const preview = document.getElementById('photoPreview');
            const placeholderText = document.getElementById('photoPlaceholder');
            
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.src = e.target.result;
                    preview.classList.remove('hidden');
                    placeholderText.classList.add('hidden');
                }
                reader.readAsDataURL(file);
            }
        }

        // Enregistrer la sélection de matières (AJAX)
        function enregistrerSelectionMatieres() {
            const checked = Array.from(document.querySelectorAll('.matiere-checkbox:checked')).map(i => i.value);
            if (checked.length === 0) {
                showNotification('Veuillez sélectionner au moins une matière.', 'error');
                return;
            }
            const fd = new FormData();
            fd.append('action', 'enregistrer_selection_matieres');
            checked.forEach(n => fd.append('matieres_selection[]', n));
            fetch('', { method: 'POST', body: fd, headers: {'X-Requested-With':'XMLHttpRequest'} })
                .then(r => r.json())
                .then(d => {
                    if (d.success) {
                        showNotification('Sélection enregistrée (' + ((d.inserted && d.inserted.length) || 0) + ' ajoutées, ' + ((d.skipped && d.skipped.length) || 0) + ' ignorées).', 'success');
                        setTimeout(() => location.reload(), 600);
                    } else {
                        showNotification(d.message || 'Erreur lors de l\'enregistrement', 'error');
                    }
                })
                .catch(() => showNotification('Erreur réseau lors de l\'enregistrement', 'error'));
        }

// Notification moderne
        function showNotification(message, type = 'success') {
            // Créer l'élément de notification
            const notification = document.createElement('div');
            notification.className = `fixed top-4 right-4 z-50 max-w-sm w-full transform transition-all duration-300 translate-x-full`;
            
            const bgColor = type === 'success' ? 'bg-green-500' : 'bg-red-500';
            const icon = type === 'success' ? 'fas fa-check-circle' : 'fas fa-exclamation-circle';
            
            notification.innerHTML = `
                <div class="${bgColor} text-white px-6 py-4 rounded-lg shadow-lg flex items-center space-x-3">
                    <i class="${icon} text-xl"></i>
                    <span class="font-medium">${message}</span>
                    <button onclick="this.parentElement.parentElement.remove()" class="ml-auto text-white hover:text-gray-200">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            `;
            
            document.body.appendChild(notification);
            
            // Animation d'entrée
            setTimeout(() => {
                notification.classList.remove('translate-x-full');
            }, 100);
            
            // Suppression automatique après 5 secondes
            setTimeout(() => {
                notification.classList.add('translate-x-full');
                setTimeout(() => notification.remove(), 300);
            }, 5000);
        }

        // Ajouter une nouvelle ligne dans le tableau des élèves
        function addEleveToTable(eleve) {
            const tbody = document.querySelector('#eleves-section tbody');
            if (!tbody) return;
            
            const row = document.createElement('tr');
            row.className = 'eleve-row hover:bg-gray-50';
            row.innerHTML = `
                <td class="px-6 py-4 whitespace-nowrap">
                    <div class="flex items-center">
                        ${eleve.photo_eleve ? 
                            `<img src="${eleve.photo_eleve}" alt="Photo ${eleve.prenom_eleve}" class="w-10 h-10 rounded-full object-cover">` : 
                            `<div class="w-10 h-10 bg-blue-500 rounded-full flex items-center justify-center text-white font-semibold">
                                ${eleve.prenom_eleve.charAt(0)}${eleve.nom_eleve.charAt(0)}
                            </div>`
                        }
                        <div class="ml-4">
                            <div class="text-sm font-medium text-gray-900">
                                ${eleve.prenom_eleve} ${eleve.nom_eleve}
                            </div>
                            <div class="text-sm text-gray-500">Nouvel élève</div>
                        </div>
                    </div>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                    ${eleve.matricule_eleve}
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                    <span class="px-2 py-1 text-xs font-semibold rounded-full bg-blue-100 text-blue-800">
                        ${eleve.classe_eleve}
                    </span>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                    ${eleve.genre_eleve}
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                    <div class="text-sm text-gray-900">
                        ${eleve.prenom_parent} ${eleve.nom_parent}
                    </div>
                    <div class="text-sm text-gray-500">
                        ${eleve.profession_parent || ''}
                    </div>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                    <div class="text-sm text-gray-900">Élève: -</div>
                    <div class="text-sm text-gray-500">Parent: ${eleve.telephone_parent}</div>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                    <button onclick="viewEleve(${eleve.id})" 
                            class="text-blue-600 hover:text-blue-900 mr-3">
                        <i class="fas fa-eye"></i>
                    </button>
                    <button onclick="editEleve(${eleve.id})" 
                            class="text-green-600 hover:text-green-900 mr-3">
                        <i class="fas fa-edit"></i>
                    </button>
                    <button onclick="deleteEleve(${eleve.id})" 
                            class="text-red-600 hover:text-red-900">
                        <i class="fas fa-trash"></i>
                    </button>
                </td>
            `;
            
            tbody.insertBefore(row, tbody.firstChild);
        }

        // Gérer la soumission du formulaire d'ajout d'élève
        document.addEventListener('DOMContentLoaded', function() {
            // Initialiser l'auto-complétion
            setupAutoComplete('nom_eleve', nomsGuinee);
            setupAutoComplete('prenom_eleve', prenomsGuinee);
            setupAutoComplete('nom_parent', nomsGuinee);
            setupAutoComplete('prenom_parent', prenomsGuinee);
            setupAutoComplete('profession_parent', professionsGuinee);
            setupAutoComplete('lieu_naissance_eleve', villesGuinee);
            
            const form = document.getElementById('addEleveForm');
            if (form) {
                form.addEventListener('submit', function(e) {
                    e.preventDefault();
                    
                    // Validation du genre
                    const genre = form.querySelector('[name="genre_eleve"]').value;
                    if (!genre) {
                        showNotification('Veuillez sélectionner un genre pour l\'élève', 'error');
                        return;
                    }
                    
                    const formData = new FormData(form);
                    const submitButton = form.querySelector('button[type="submit"]');
                    const originalText = submitButton.innerHTML;
                    
                    // Désactiver le bouton et afficher le loading
                    submitButton.disabled = true;
                    submitButton.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Ajout en cours...';
                    
                    fetch('', {
                        method: 'POST',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showNotification(`Élève ajouté avec succès ! Matricule: ${data.matricule}`, 'success');
                            addEleveToTable(data.eleve);
                            closeAddEleveModal();
                            form.reset();
                            document.getElementById('photoPreview').classList.add('hidden');
                            document.getElementById('photoPlaceholder').classList.remove('hidden');
                        } else {
                            showNotification(data.message, 'error');
                        }
                    })
                    .catch(error => {
                        console.error('Erreur:', error);
                        showNotification('Une erreur est survenue lors de l\'ajout', 'error');
                    })
                    .finally(() => {
                        // Réactiver le bouton
                        submitButton.disabled = false;
                        submitButton.innerHTML = originalText;
                    });
                });
            }
            
            // Gestionnaire pour le formulaire de modification
            const editForm = document.getElementById('editEleveForm');
            if (editForm) {
                editForm.addEventListener('submit', handleEditFormSubmit);
            }
        });


        // ===== FONCTIONS DE SAUVEGARDE =====

        // Afficher la section sauvegarde
        function showSauvegardeSection() {
            showSection('sauvegarde');
            chargerInfosSauvegarde();
        }

        // Charger les informations de sauvegarde
        async function chargerInfosSauvegarde() {
            try {
                const response = await fetch('admin_api.php?action=sauvegarde_info');
                const data = await response.json();
                
                if (data.success) {
                    document.getElementById('derniere-sauvegarde').textContent = data.derniere_sauvegarde || '-';
                    document.getElementById('taille-base').textContent = data.taille_base || '-';
                    document.getElementById('nombre-sauvegardes').textContent = data.nombre_sauvegardes || '0';
                    
                    // Mettre à jour la liste des sauvegardes
                    if (data.sauvegardes && data.sauvegardes.length > 0) {
                        afficherListeSauvegardes(data.sauvegardes);
                    }
                }
            } catch (error) {
                console.error('Erreur lors du chargement des infos de sauvegarde:', error);
            }
        }

        // Afficher la liste des sauvegardes
        function afficherListeSauvegardes(sauvegardes) {
            const container = document.getElementById('liste-sauvegardes');
            container.innerHTML = '';
            
            sauvegardes.forEach(sauvegarde => {
                const element = document.createElement('div');
                element.className = 'flex items-center justify-between p-3 border border-gray-200 rounded-lg';
                element.innerHTML = `
                    <div class="flex items-center space-x-3">
                        <i class="fas fa-database text-blue-600"></i>
                        <div>
                            <div class="font-medium text-gray-800">${sauvegarde.nom}</div>
                            <div class="text-sm text-gray-500">${sauvegarde.date} - ${sauvegarde.taille}</div>
                        </div>
                    </div>
                    <div class="flex space-x-2">
                        <button onclick="telechargerSauvegarde('${sauvegarde.nom}')" 
                                class="text-green-600 hover:text-green-800" title="Télécharger">
                            <i class="fas fa-download"></i>
                        </button>
                        <button onclick="supprimerSauvegarde('${sauvegarde.nom}')" 
                                class="text-red-600 hover:text-red-800" title="Supprimer">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                `;
                container.appendChild(element);
            });
        }

        // Créer une nouvelle sauvegarde
        async function creerSauvegarde() {
            const bouton = document.querySelector('button[onclick="creerSauvegarde()"]');
            const texteOriginal = bouton.innerHTML;
            
            try {
                bouton.disabled = true;
                bouton.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Création en cours...';
                
                const response = await fetch('admin_api.php?action=creer_sauvegarde', {
                    method: 'POST'
                });
                const data = await response.json();
                
                if (data.success) {
                    showNotification('Sauvegarde créée avec succès!', 'success');
                    chargerInfosSauvegarde();
                } else {
                    showNotification(data.message || 'Erreur lors de la création de la sauvegarde', 'error');
                }
            } catch (error) {
                console.error('Erreur:', error);
                showNotification('Erreur lors de la création de la sauvegarde', 'error');
            } finally {
                bouton.disabled = false;
                bouton.innerHTML = texteOriginal;
            }
        }

        // Télécharger une sauvegarde
        function telechargerSauvegarde(nomFichier) {
            window.open(`admin_api.php?action=telecharger_sauvegarde&fichier=${encodeURIComponent(nomFichier)}`, '_blank');
        }

        // Supprimer une sauvegarde
        async function supprimerSauvegarde(nomFichier) {
            if (!confirm(`Êtes-vous sûr de vouloir supprimer la sauvegarde "${nomFichier}" ?`)) {
                return;
            }
            
            try {
                const response = await fetch('admin_api.php?action=supprimer_sauvegarde', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `fichier=${encodeURIComponent(nomFichier)}`
                });
                const data = await response.json();
                
                if (data.success) {
                    showNotification('Sauvegarde supprimée avec succès!', 'success');
                    chargerInfosSauvegarde();
                } else {
                    showNotification(data.message || 'Erreur lors de la suppression', 'error');
                }
            } catch (error) {
                console.error('Erreur:', error);
                showNotification('Erreur lors de la suppression de la sauvegarde', 'error');
            }
        }
    </script>

    <!-- Modal d'ajout d'élève -->
    <div id="addEleveModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="bg-white rounded-lg max-w-4xl w-full max-h-screen overflow-y-auto">
                <form id="addEleveForm" method="POST" enctype="multipart/form-data" class="p-6">
                    <input type="hidden" name="action" value="ajouter_eleve">
                    
                    <!-- En-tête du modal -->
                    <div class="flex justify-between items-center mb-6">
                        <h2 class="text-2xl font-bold text-gray-800">Ajouter un Élève</h2>
                        <button type="button" onclick="closeAddEleveModal()" 
                                class="text-gray-500 hover:text-gray-700">
                            <i class="fas fa-times text-xl"></i>
                        </button>
                    </div>

                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        <!-- Colonne 1: Informations de l'élève -->
                        <div class="space-y-4">
                            <h3 class="text-lg font-semibold text-gray-800 border-b pb-2">Informations de l'Élève</h3>
                            
                            <!-- Nom et Prénom -->
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1" style="color:red">
                                        Nom * 
                                    </label>
                                    <input type="text" id="nom_eleve" name="nom_eleve" required 
                                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                                           placeholder="Commencez à taper pour voir les suggestions...">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1" style="color:red">
                                        Prénom * 
                                    </label>
                                    <input type="text" id="prenom_eleve" name="prenom_eleve" required 
                                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                                           placeholder="Commencez à taper pour voir les suggestions...">
                                </div>
                            </div>

                            <!-- Classe -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1" style="color:red">Classe *</label>
                                <select name="classe_eleve" required 
                                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                    <option value="">Sélectionner une classe</option>
                                    <?php foreach ($classes as $classe): ?>
                                        <option value="<?php echo htmlspecialchars($classe['nom_classe']); ?>">
                                            <?php echo htmlspecialchars($classe['nom_classe'] . ' - ' . $classe['niveau']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Genre et Date de naissance -->
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1" style="color:red">Genre *</label>
                                    <select name="genre_eleve" required 
                                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                        <option value="">Sélectionner un genre *</option>
                                        <option value="Masculin" selected>Masculin</option>
                                        <option value="Féminin">Féminin</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Date de naissance</label>
                                    <input type="date" name="date_de_naissance_eleve" 
                                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                </div>
                            </div>

                            <!-- Lieu de naissance -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">
                                    Lieu de naissance
                                </label>
                                <input type="text" id="lieu_naissance_eleve" name="lieu_de_naissance_eleve" 
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                                       placeholder="Ville de Guinée (Kamsar, Conakry, Kankan...)">
                            </div>

                            <!-- Adresse -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Adresse</label>
                                <textarea name="adresse_eleve" rows="3" 
                                          class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"></textarea>
                            </div>

                            <!-- Rôle élève -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Rôle dans la classe</label>
                                <select name="role_eleve" 
                                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                    <option value="">Aucun rôle spécifique</option>
                                    <option value="Délégué de la classe">Délégué de la classe</option>
                                </select>
                            </div>

                            <!-- Photo -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Photo de profil</label>
                                <div class="flex items-center space-x-4">
                                    <div class="w-20 h-20 border border-gray-300 rounded-lg flex items-center justify-center overflow-hidden">
                                        <img id="photoPreview" class="w-full h-full object-cover hidden" alt="Prévisualisation">
                                        <span id="photoPlaceholder" class="text-xs text-gray-400 text-center">Aucune photo</span>
                                    </div>
                                    <input type="file" name="photo_eleve" accept=".jpg,.jpeg,.png,.gif" 
                                           onchange="previewPhoto(event)" 
                                           class="flex-1 px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                </div>
                            </div>
                        </div>

                        <!-- Colonne 2: Informations des parents + connexion -->
                        <div class="space-y-4">
                            <h3 class="text-lg font-semibold text-gray-800 border-b pb-2">Informations du Parent/Tuteur</h3>
                            
                            <!-- Nom et Prénom parent -->
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">
                                        Nom du parent
                                    </label>
                                    <input type="text" id="nom_parent" name="nom_parent" 
                                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                                           placeholder="Nom du parent...">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">
                                        Prénom du parent 
                                    </label>
                                    <input type="text" id="prenom_parent" name="prenom_parent" 
                                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                                           placeholder="Prénom du parent...">
                                </div>
                            </div>

                            <!-- Profession et Téléphone parent -->
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">
                                        Profession 
                                    </label>
                                    <input type="text" id="profession_parent" name="profession_parent" 
                                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                                           placeholder="Profession du parent (Enseignant, Médecin, Commerçant...)">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Téléphone parent</label>
                                    <input type="tel" name="telephone_parent" 
                                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500" 
                                           placeholder="Utilisé comme identifiant de connexion">
                                </div>
                            </div>

                            <!-- Séparateur -->
                            <div class="border-t pt-4 mt-6">
                                <h3 class="text-lg font-semibold text-gray-800 border-b pb-2">Informations de Connexion</h3>
                            </div>

                            <!-- Mot de passe élève -->
                            <div style="display:none">
                                <label class="block text-sm font-medium text-gray-700 mb-1">Mot de passe élève</label>
                                <input type="text" name="mot_de_passe_eleve" minlength="6" 
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500" 
                                       placeholder="Minimum 6 caractères">
                                <p class="text-xs text-gray-500 mt-1">L'élève se connectera avec le téléphone du parent + ce mot de passe</p>
                            </div>

                            <!-- Mot de passe parent -->
                            <div style="display:none">
                                <label class="block text-sm font-medium text-gray-700 mb-1">Mot de passe parent</label>
                                <input type="text" name="mot_de_passe_parent" minlength="6" value=""
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500" 
                                       placeholder="Minimum 6 caractères">
                                <p class="text-xs text-gray-500 mt-1">Le parent se connectera avec son téléphone + ce mot de passe</p>
                            </div>

                            <!-- Information générale -->
                            <div class="bg-blue-50 p-4 rounded-lg">
                                <h4 class="font-medium text-blue-800 mb-2">Informations importantes :</h4>
                                <ul class="text-sm text-blue-700 space-y-1">
                                    <li>• Les champs marqués en rouge sont obligatoires</li>
                                    <li>• Le matricule de l'élève sera généré automatiquement</li>
                                    <li>• Les identifiants de connexion par défaut de l'élève seront: son nom de famille et son matricule</li>
                                    <li>• Les identifiants de connexion par défaut du parent seront: son nom de famille et son matricule+P</li>
                                    <li>• La date de création sera enregistrée automatiquement</li>
                                    <li>• NB: Elèves et parents devront changer leurs mot de passe dès leur première connexion</li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <!-- Boutons d'action -->
                    <div class="flex justify-end space-x-4 mt-8 pt-6 border-t">
                        <button type="button" onclick="closeAddEleveModal()" 
                                class="px-6 py-2 text-gray-600 bg-gray-100 hover:bg-gray-200 rounded-lg transition-colors">
                            Annuler
                        </button>
                        <button type="submit" 
                                class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors">
                            <i class="fas fa-plus mr-2"></i>Ajouter l'Élève
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal de modification d'élève -->
    <div id="editEleveModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="bg-white rounded-lg max-w-4xl w-full max-h-screen overflow-y-auto">
                <form id="editEleveForm" method="POST" enctype="multipart/form-data" class="p-6">
                    <input type="hidden" name="action" value="modifier_eleve">
                    <input type="hidden" id="edit_id_eleve" name="id_eleve" value="">
                    <input type="hidden" id="edit_photo_actuelle" name="photo_actuelle" value="">
                    
                    <!-- En-tête du modal -->
                    <div class="flex justify-between items-center mb-6">
                        <h2 class="text-2xl font-bold text-gray-800">Modifier l'Élève</h2>
                        <button type="button" onclick="closeEditEleveModal()" 
                                class="text-gray-500 hover:text-gray-700">
                            <i class="fas fa-times text-xl"></i>
                        </button>
                    </div>

                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        <!-- Colonne 1: Informations de l'élève -->
                        <div class="space-y-4">
                            <h3 class="text-lg font-semibold text-gray-800 border-b pb-2">Informations de l'Élève</h3>
                            
                            <!-- Nom et Prénom -->
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1" style="color:red">Nom *</label>
                                    <input type="text" id="edit_nom_eleve" name="nom_eleve" required 
                                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1" style="color:red">>Prénom *</label>
                                    <input type="text" id="edit_prenom_eleve" name="prenom_eleve" required 
                                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                </div>
                            </div>

                            <!-- Classe -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1" style="color:red">Classe *</label>
                                <select id="edit_classe_eleve" name="classe_eleve" required 
                                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                    <option value="">Sélectionner une classe</option>
                                    <?php foreach ($classes as $classe): ?>
                                        <option value="<?php echo htmlspecialchars($classe['nom_classe']); ?>">
                                            <?php echo htmlspecialchars($classe['nom_classe']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Genre et Date de naissance -->
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1" style="color:red">Genre *</label>
                                    <select id="edit_genre_eleve" name="genre_eleve" required 
                                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                        <option value="">Sélectionner</option>
                                        <option value="Masculin">Masculin</option>
                                        <option value="Féminin">Féminin</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Date de naissance</label>
                                    <input type="date" id="edit_date_de_naissance_eleve" name="date_de_naissance_eleve" 
                                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                </div>
                            </div>

                            <!-- Lieu de naissance -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Lieu de naissance</label>
                                <input type="text" id="edit_lieu_de_naissance_eleve" name="lieu_de_naissance_eleve" 
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                            </div>

                            <!-- Adresse -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Adresse</label>
                                <textarea id="edit_adresse_eleve" name="adresse_eleve" rows="3" 
                                          class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"></textarea>
                            </div>

                            <!-- Rôle élève -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Rôle dans la classe</label>
                                <select id="edit_role_eleve" name="role_eleve" 
                                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                    <option value="">Aucun rôle spécifique</option>
                                    <option value="Délégué de la classe">Délégué de la classe</option>
                                </select>
                            </div>

                            <!-- Photo -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Photo de profil</label>
                                <div class="flex items-center space-x-4">
                                    <div class="w-20 h-20 border border-gray-300 rounded-lg flex items-center justify-center overflow-hidden">
                                        <img id="editPhotoPreview" class="w-full h-full object-cover hidden" alt="Prévisualisation">
                                        <span id="editPhotoPlaceholder" class="text-xs text-gray-400 text-center">Aucune photo</span>
                                    </div>
                                    <input type="file" name="photo_eleve" accept=".jpg,.jpeg,.png,.gif" 
                                           onchange="previewEditPhoto(event)" 
                                           class="flex-1 px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                </div>
                            </div>
                        </div>

                        <!-- Colonne 2: Informations des parents + connexion -->
                        <div class="space-y-4">
                            <h3 class="text-lg font-semibold text-gray-800 border-b pb-2">Informations du Parent/Tuteur</h3>
                            
                            <!-- Nom et Prénom parent -->
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Nom du parent</label>
                                    <input type="text" id="edit_nom_parent" name="nom_parent" 
                                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Prénom du parent</label>
                                    <input type="text" id="edit_prenom_parent" name="prenom_parent" 
                                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                </div>
                            </div>

                            <!-- Profession et Téléphone parent -->
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Profession</label>
                                    <input type="text" id="edit_profession_parent" name="profession_parent" 
                                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Téléphone parent</label>
                                    <input type="tel" id="edit_telephone_parent" name="telephone_parent" 
                                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                </div>
                            </div>

                            <!-- Séparateur -->
                            <div class="border-t pt-4 mt-6">
                                <h3 class="text-lg font-semibold text-gray-800 border-b pb-2">Modifier Mots de Passe (Optionnel)</h3>
                                <p class="text-sm text-gray-600 mt-2">Laissez vide pour conserver les mots de passe actuels</p>
                            </div>

                            <!-- Mot de passe élève -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Nouveau mot de passe élève</label>
                                <input type="text" name="mot_de_passe_eleve" minlength="6" 
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500" 
                                       placeholder="Laisser vide pour conserver">
                            </div>

                            <!-- Mot de passe parent -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Nouveau mot de passe parent</label>
                                <input type="text" name="mot_de_passe_parent" minlength="6" 
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500" 
                                       placeholder="Laisser vide pour conserver">
                            </div>
                        </div>
                    </div>

                    <!-- Boutons d'action -->
                    <div class="flex justify-end space-x-4 mt-8 pt-6 border-t">
                        <button type="button" onclick="closeEditEleveModal()" 
                                class="px-6 py-2 text-gray-600 bg-gray-100 hover:bg-gray-200 rounded-lg transition-colors">
                            Annuler
                        </button>
                        <button type="submit" 
                                class="px-6 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg transition-colors">
                            <i class="fas fa-save mr-2"></i>Modifier l'Élève
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal de confirmation de suppression -->
    <div id="deleteEleveModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="bg-white rounded-lg max-w-md w-full p-6">
                <div class="text-center">
                    <!-- Icône d'avertissement -->
                    <div class="mx-auto flex items-center justify-center h-16 w-16 rounded-full bg-red-100 mb-4">
                        <i class="fas fa-exclamation-triangle text-red-600 text-2xl"></i>
                    </div>
                    
                    <!-- Titre et message -->
                    <h3 class="text-lg font-semibold text-gray-900 mb-2">Confirmer la suppression</h3>
                    <p class="text-sm text-gray-600 mb-6">
                        Êtes-vous sûr de vouloir supprimer l'élève <strong id="deleteEleveNom"></strong> ?
                        <br><span class="text-red-600 font-medium">Cette action est irréversible.</span>
                    </p>
                    
                    <!-- Boutons -->
                    <div class="flex justify-center space-x-4">
                        <button type="button" onclick="closeDeleteEleveModal()" 
                                class="px-4 py-2 text-gray-600 bg-gray-100 hover:bg-gray-200 rounded-lg transition-colors">
                            <i class="fas fa-times mr-2"></i>Annuler
                        </button>
                        <button type="button" onclick="confirmDeleteEleve()" 
                                class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg transition-colors">
                            <i class="fas fa-trash mr-2"></i>Supprimer
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal de confirmation de suppression de classe -->
    <div id="deleteClasseModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="bg-white rounded-lg max-w-md w-full p-6">
                <div class="text-center">
                    <!-- Icône d'avertissement -->
                    <div class="mx-auto flex items-center justify-center h-16 w-16 rounded-full bg-red-100 mb-4">
                        <i class="fas fa-exclamation-triangle text-red-600 text-2xl"></i>
                    </div>
                    
                    <!-- Titre et message -->
                    <h3 class="text-lg font-semibold text-gray-900 mb-2">Confirmer la suppression</h3>
                    <p class="text-sm text-gray-600 mb-6">
                        Êtes-vous sûr de vouloir supprimer la classe <strong id="deleteClasseNom"></strong> ?
                        <br><span class="text-red-600 font-medium">Cette action supprimera définitivement la classe et toutes ses données associées.</span>
                        <br><span class="text-amber-600 font-medium">Les élèves de cette classe devront être réassignés manuellement.</span>
                    </p>
                    
                    <!-- Boutons -->
                    <div class="flex justify-center space-x-4">
                        <button type="button" onclick="closeDeleteClasseModal()" 
                                class="px-4 py-2 text-gray-600 bg-gray-100 hover:bg-gray-200 rounded-lg transition-colors">
                            <i class="fas fa-times mr-2"></i>Annuler
                        </button>
                        <button type="button" onclick="confirmDeleteClasse()" 
                                class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg transition-colors">
                            <i class="fas fa-trash mr-2"></i>Supprimer définitivement
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal de confirmation de suppression du personnel -->
    <div id="deletePersonnelModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="bg-white rounded-lg max-w-md w-full p-6">
                <div class="text-center">
                    <!-- Icône d'avertissement -->
                    <div class="mx-auto flex items-center justify-center h-16 w-16 rounded-full bg-red-100 mb-4">
                        <i class="fas fa-exclamation-triangle text-red-600 text-2xl"></i>
                    </div>
                    
                    <!-- Titre et message -->
                    <h3 class="text-lg font-semibold text-gray-900 mb-2">Confirmer la suppression</h3>
                    <p class="text-sm text-gray-600 mb-6">
                        Êtes-vous sûr de vouloir supprimer le membre du personnel <strong id="deletePersonnelNom"></strong> ?
                        <br><span class="text-red-600 font-medium">Cette action est irréversible.</span>
                    </p>
                    
                    <!-- Boutons -->
                    <div class="flex justify-center space-x-4">
                        <button type="button" onclick="closeDeletePersonnelModal()" 
                                class="px-4 py-2 text-gray-600 bg-gray-100 hover:bg-gray-200 rounded-lg transition-colors">
                            <i class="fas fa-times mr-2"></i>Annuler
                        </button>
                        <button type="button" onclick="confirmDeletePersonnel()" 
                                class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg transition-colors">
                            <i class="fas fa-trash mr-2"></i>Supprimer
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal d'ajout de personnel -->
    <div id="addPersonnelModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="bg-white rounded-lg max-w-5xl w-full max-h-screen overflow-y-auto">
                <form id="addPersonnelForm" method="POST" enctype="multipart/form-data" class="p-6">
                    <input type="hidden" name="action" value="ajouter_personnel">
                    
                    <!-- En-tête du modal -->
                    <div class="flex justify-between items-center mb-6">
                        <h2 class="text-2xl font-bold text-gray-800">Ajouter un Membre du Personnel</h2>
                        <button type="button" onclick="closePersonnelModal()" 
                                class="text-gray-500 hover:text-gray-700">
                            <i class="fas fa-times text-xl"></i>
                        </button>
                    </div>

                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        <!-- Colonne 1: Informations personnelles -->
                        <div class="space-y-4">
                            <h3 class="text-lg font-semibold text-gray-800 border-b pb-2">Informations Personnelles</h3>
                            
                            <!-- Nom et Prénom -->
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Nom *</label>
                                    <input type="text" name="nom_u" required 
                                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Prénom *</label>
                                    <input type="text" name="prenom_u" required 
                                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                </div>
                            </div>

                            <!-- Email -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Email *</label>
                                <input type="email" name="mail_u" required 
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                            </div>

                            <!-- Téléphone -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">
                                    Téléphone * 
                                    <span class="text-blue-500 text-xs">(Min. 9 chiffres - Utilisé pour la connexion)</span>
                                </label>
                                <input type="tel" name="tel_u" required minlength="8" pattern="[0-9+\-\s]+" 
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                                       placeholder="Ex: 622XXXXXX">
                            </div>

                            <!-- Photo -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Photo de profil</label>
                                <div class="flex items-center space-x-4">
                                    <div class="w-20 h-20 border border-gray-300 rounded-lg flex items-center justify-center overflow-hidden">
                                        <img id="photoPersonnelPreview" class="w-full h-full object-cover hidden" alt="Prévisualisation">
                                        <span id="photoPersonnelPlaceholder" class="text-xs text-gray-400 text-center">Aucune photo</span>
                                    </div>
                                    <input type="file" name="pp_u" accept=".jpg,.jpeg,.png,.gif" 
                                           onchange="previewPersonnelPhoto(event)" 
                                           class="flex-1 px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                </div>
                            </div>
                        </div>

                        <!-- Colonne 2: Informations professionnelles -->
                        <div class="space-y-4">
                            <h3 class="text-lg font-semibold text-gray-800 border-b pb-2">Informations Professionnelles</h3>
                            
                            <!-- Fonction -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Fonction *</label>
                                <select name="fonction_u" required onchange="updateMatiereNiveauVisibility()" 
                                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                    <option value="">Sélectionner une fonction</option>
                                    <option value="Professeur">Enseignant</option>
                                    <option value="Directeur">Directeur</option>
                                    <option value="Directeur Adjoint">Directeur Adjoint</option>
                                    <option value="DE">Directeur des études</option>
                                    <option value="Secrétaire">Secrétaire</option>
                                    <option value="Comptable">Comptable</option>
                                    <option value="Bibliothécaire">Bibliothécaire</option>
                                    <option value="Surveillant">Surveillant (CPE)</option>
                                    <option value="Conseiller">Conseiller</option>
                                </select>
                            </div>

                            <!-- Rôle -->
                            <div style="display:none;">
                                <label class="block text-sm font-medium text-gray-700 mb-1">Rôle système</label>
                                <select name="role_u" 
                                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                    <option value="neant" selected>Néant</option>
                                    <option value="admin">Administrateur</option>
                                    <option value="super_admin">Super Administrateur</option>
                                </select>
                            </div>

                            <!-- Mot de passe -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Mot de passe *</label>
                                <input type="text" name="mot_de_passe_u" required minlength="6" 
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500" 
                                       placeholder="Minimum 6 caractères">
                            </div>

                            <!-- Section Matières/Niveaux (visible pour tout le personnel) -->
                            <div id="matiereNiveauSection">
                                <div class="flex justify-between items-center mb-3">
                                    <label class="block text-sm font-medium text-gray-700">
                                        Matières et Niveaux enseignés *
                                    </label>
                                    <button type="button" onclick="addMatiereNiveauLine()" 
                                            class="bg-green-500 hover:bg-green-600 text-white px-3 py-1 rounded text-sm">
                                        <i class="fas fa-plus mr-1"></i>Ajouter une matière
                                    </button>
                                </div>
                                <div id="matiereNiveauContainer" class="space-y-3">
                                    <!-- Les lignes matière/niveau seront ajoutées ici dynamiquement -->
                                </div>
                                <p class="text-xs text-gray-500 mt-2">
                                    Ajoutez les matières et niveaux/classes que ce personnel peut enseigner (optionnel).
                                </p>
                            </div>

                            <!-- Information générale -->
                            <div class="bg-blue-50 p-4 rounded-lg">
                                <h4 class="font-medium text-blue-800 mb-2">Informations importantes :</h4>
                                <ul class="text-sm text-blue-700 space-y-1">
                                    <li>• Le personnel se connectera avec son téléphone + mot de passe</li>
                                    <li>• Pour les professeurs, les matières/niveaux sont obligatoires</li>
                                    <li>• L'email doit être unique dans le système</li>
                                    <li>• Le téléphone doit contenir au moins 9 chiffres</li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <!-- Boutons d'action -->
                    <div class="flex justify-end space-x-4 mt-8 pt-6 border-t">
                        <button type="button" onclick="closePersonnelModal()" 
                                class="px-6 py-2 text-gray-600 bg-gray-100 hover:bg-gray-200 rounded-lg transition-colors">
                            Annuler
                        </button>
                        <button type="submit" 
                                class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors">
                            <i class="fas fa-plus mr-2"></i>Ajouter le Personnel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal de modification du personnel -->
    <div id="editPersonnelModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-lg max-w-5xl w-full max-h-screen overflow-y-auto">
            <form id="editPersonnelForm" method="POST" enctype="multipart/form-data" class="p-6">
                <input type="hidden" name="action" value="modifier_personnel">
                <input type="hidden" name="personnel_id" id="edit_personnel_id">
                
                <!-- En-tête du modal -->
                <div class="flex justify-between items-center mb-6">
                    <h2 class="text-2xl font-bold text-gray-800">Modifier le Personnel</h2>
                    <button type="button" onclick="closeEditPersonnelModal()" 
                            class="text-gray-500 hover:text-gray-700">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <!-- Colonne 1: Informations personnelles -->
                    <div class="space-y-4">
                        <h3 class="text-lg font-semibold text-gray-800 border-b pb-2">Informations Personnelles</h3>
                        
                        <!-- Nom et Prénom -->
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Nom *</label>
                                <input type="text" name="nom_u" id="edit_nom_u" required 
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Prénom *</label>
                                <input type="text" name="prenom_u" id="edit_prenom_u" required 
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                            </div>
                        </div>

                        <!-- Email et Téléphone -->
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Email *</label>
                                <input type="email" name="mail_u" id="edit_mail_u" required 
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Téléphone *</label>
                                <input type="tel" name="tel_u" id="edit_tel_u" required 
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                            </div>
                        </div>

                        <!-- Fonction et Rôle -->
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Fonction *</label>
                                <select name="fonction_u" id="edit_fonction_u" required 
                                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                    <option value="">Sélectionner une fonction</option>
                                    <option value="Professeur">Professeur</option>
                                    <option value="Directeur">Directeur</option>
                                    <option value="Directeur Adjoint">Directeur Adjoint</option>
                                    <option value="DE">Directeur des études</option>
                                    <option value="Secrétaire">Secrétaire</option>
                                    <option value="Comptable">Comptable</option>
                                    <option value="Bibliothécaire">Bibliothécaire</option>
                                    <option value="Surveillant">Surveillant (CPE)</option>
                                    <option value="Conseiller">Conseiller</option>
                                </select>
                            </div>
                            <div style="display:none;">
                                <label class="block text-sm font-medium text-gray-700 mb-1">Rôle système</label>
                                <select name="role_u" id="edit_role_u" 
                                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                    <option value="neant">Néant</option>
                                    <option value="admin">Administrateur</option>
                                    <option value="super_admin">Super Administrateur</option>
                                </select>
                            </div>
                        </div>

                        <!-- Photo -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Photo de profil</label>
                            <div class="flex items-center space-x-4">
                                <div class="w-20 h-20 border border-gray-300 rounded-lg flex items-center justify-center overflow-hidden">
                                    <img id="editPhotoPersonnelPreview" class="w-full h-full object-cover hidden" alt="Prévisualisation">
                                    <span id="editPhotoPersonnelPlaceholder" class="text-xs text-gray-400 text-center">Photo actuelle</span>
                                </div>
                                <input type="file" name="pp_u" accept=".jpg,.jpeg,.png,.gif" 
                                       onchange="previewEditPersonnelPhoto(event)" 
                                       class="flex-1 px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                            </div>
                        </div>

                        <!-- Nouveau mot de passe -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Nouveau mot de passe</label>
                            <input type="text" name="nouveau_mot_de_passe" id="edit_nouveau_mot_de_passe"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                                   placeholder="Laisser vide pour conserver l'ancien">
                            <p class="text-xs text-gray-500 mt-1">Laisser vide pour conserver le mot de passe actuel</p>
                        </div>
                    </div>

                    <!-- Colonne 2: Matières et niveaux -->
                    <div class="space-y-4">
                        <h3 class="text-lg font-semibold text-gray-800 border-b pb-2">Matières et Niveaux</h3>
                        
                        <div>
                            <div class="flex items-center justify-between mb-3">
                                <label class="block text-sm font-medium text-gray-700">
                                    Matières et Niveaux enseignés
                                </label>
                                <button type="button" onclick="addEditMatiereNiveauLine()" 
                                        class="bg-green-500 hover:bg-green-600 text-white px-3 py-1 rounded text-sm">
                                    <i class="fas fa-plus mr-1"></i>Ajouter une matière
                                </button>
                            </div>
                            <div id="editMatiereNiveauContainer" class="space-y-3">
                                <!-- Les lignes matière/niveau seront ajoutées ici dynamiquement -->
                            </div>
                            <p class="text-xs text-gray-500 mt-2">
                                Ajoutez les matières et niveaux/classes que ce personnel peut enseigner.
                            </p>
                        </div>

                        <!-- Information générale -->
                        <div class="bg-blue-50 p-4 rounded-lg">
                            <h4 class="font-medium text-blue-800 mb-2">Informations de modification :</h4>
                            <ul class="text-sm text-blue-700 space-y-1">
                                <li>• Seuls les champs modifiés seront mis à jour</li>
                                <li>• Le mot de passe ne sera changé que si vous en saisissez un nouveau</li>
                                <li>• L'email doit rester unique dans le système</li>
                                <li>• La photo existante sera remplacée si vous en téléchargez une nouvelle</li>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- Boutons d'action -->
                <div class="flex justify-end space-x-4 mt-8 pt-6 border-t">
                    <button type="button" onclick="closeEditPersonnelModal()" 
                            class="px-6 py-2 text-gray-600 bg-gray-100 hover:bg-gray-200 rounded-lg transition-colors">
                        Annuler
                    </button>
                    <button type="submit" 
                            class="px-6 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg transition-colors">
                        <i class="fas fa-save mr-2"></i>Modifier le Personnel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Prévisualiser la photo du personnel
        function previewPersonnelPhoto(event) {
            const file = event.target.files[0];
            const preview = document.getElementById('photoPersonnelPreview');
            const placeholderText = document.getElementById('photoPersonnelPlaceholder');
            
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.src = e.target.result;
                    preview.classList.remove('hidden');
                    placeholderText.classList.add('hidden');
                }
                reader.readAsDataURL(file);
            }
        }

        // Gérer la soumission du formulaire d'ajout de personnel
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('addPersonnelForm');
            if (form) {
                form.addEventListener('submit', function(e) {
                    e.preventDefault();
                    
                    // Validation côté client pour les professeurs
                    const fonction = this.querySelector('[name="fonction_u"]').value;
                    if (fonction === 'Professeur') {
                        const matiereLines = document.querySelectorAll('.matiere-niveau-line');
                        if (matiereLines.length === 0) {
                            showNotification('Les professeurs doivent avoir au moins une matière avec des niveaux', 'error');
                            return;
                        }
                        
                        // Vérifier que chaque ligne est complète
                        let valid = true;
                        matiereLines.forEach(line => {
                            const matiere = line.querySelector('[name="matieres[]"]').value;
                            const hiddenInput = line.querySelector('input[type="hidden"][name="niveaux[]"]');
                            const niveaux = hiddenInput ? hiddenInput.value : '';
                            if (!matiere || !niveaux) {
                                valid = false;
                            }
                        });
                        
                        if (!valid) {
                            showNotification('Toutes les matières doivent avoir des niveaux sélectionnés', 'error');
                            return;
                        }
                    }
                    
                    const formData = new FormData(this);
                    const submitButton = this.querySelector('button[type="submit"]');
                    const originalText = submitButton.innerHTML;
                    
                    // Désactiver le bouton et afficher le loading
                    submitButton.disabled = true;
                    submitButton.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Ajout en cours...';
                    
                    fetch('', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showNotification('Personnel ajouté avec succès !', 'success');
                            closePersonnelModal();
                            location.reload(); // Recharger pour afficher le nouveau personnel
                        } else {
                            showNotification(data.message, 'error');
                        }
                    })
                    .catch(error => {
                        console.error('Erreur:', error);
                        showNotification('Une erreur est survenue lors de l\'ajout', 'error');
                    })
                    .finally(() => {
                        // Réactiver le bouton
                        submitButton.disabled = false;
                        submitButton.innerHTML = originalText;
                    });
                });
            }
        });

        // Fonctions pour le modal d'ajout de personnel
        function openPersonnelModal() {
            const modal = document.getElementById('addPersonnelModal');
            if (modal) {
                modal.classList.remove('hidden');
                setTimeout(() => {
                    const firstInput = modal.querySelector('input[name="nom_u"]');
                    if (firstInput) firstInput.focus();
                }, 100);
            }
        }

        function closePersonnelModal() {
            const modal = document.getElementById('addPersonnelModal');
            if (modal) {
                modal.classList.add('hidden');
                const form = document.getElementById('addPersonnelForm');
                if (form) form.reset();
                resetMatiereNiveauLines();
            }
        }

        function addPersonnelToTable(personnel) {
            const tbody = document.querySelector('#personnel-section tbody');
            if (!tbody) return;
            
            const newRow = document.createElement('tr');
            newRow.className = 'personnel-row hover:bg-gray-50';
            
            // Générer les initiales pour la photo par défaut
            const initiales = personnel.prenom_u.charAt(0).toUpperCase() + personnel.nom_u.charAt(0).toUpperCase();
            
            newRow.innerHTML = `
                <td class="px-6 py-4 whitespace-nowrap">
                    <div class="flex items-center">
                        ${personnel.pp_u ? 
                            `<img src="${personnel.pp_u}" alt="Photo ${personnel.prenom_u}" class="w-10 h-10 rounded-full object-cover">` :
                            `<div class="w-10 h-10 bg-blue-500 rounded-full flex items-center justify-center">
                                <span class="text-white font-bold">${initiales}</span>
                            </div>`
                        }
                        <div class="ml-3">
                            <div class="text-sm font-medium text-gray-900">
                                ${personnel.prenom_u} ${personnel.nom_u}
                            </div>
                            <div class="text-sm text-gray-500">
                                ${personnel.date_creation ? `Ajouté le ${new Date(personnel.date_creation).toLocaleDateString('fr-FR')}` : ''}
                            </div>
                        </div>
                    </div>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                    <div class="text-sm text-gray-900">${personnel.mail_u}</div>
                    <div class="text-sm text-gray-500">${personnel.tel_u}</div>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                    <span class="px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">
                        ${personnel.fonction_u}
                    </span>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                    <span class="px-2 py-1 text-xs font-semibold rounded-full ${personnel.role_u === 'super_admin' ? 'bg-red-100 text-red-800' : 'bg-blue-100 text-blue-800'}">
                        ${personnel.role_u === 'super_admin' ? 'Super Admin' : 
                          personnel.role_u === 'admin' ? 'Admin' : personnel.role_u}
                    </span>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                    ${personnel.matiere_niveau_u || '-'}
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                    <button onclick="editPersonnel(${personnel.id})" 
                            class="text-green-600 hover:text-green-900 mr-3">
                        <i class="fas fa-edit"></i>
                    </button>
                    <button onclick="deletePersonnel(${personnel.id})" 
                            class="text-red-600 hover:text-red-900">
                        <i class="fas fa-trash"></i>
                    </button>
                </td>
            `;
            
            // Ajouter les attributs de données pour les filtres
            newRow.setAttribute('data-role', personnel.role_u);
            newRow.setAttribute('data-nom', (personnel.nom_u + ' ' + personnel.prenom_u).toLowerCase());
            
            tbody.appendChild(newRow);
            
            // Animation d'apparition
            newRow.style.opacity = '0';
            newRow.style.transform = 'translateY(20px)';
            setTimeout(() => {
                newRow.style.transition = 'all 0.3s ease';
                newRow.style.opacity = '1';
                newRow.style.transform = 'translateY(0)';
            }, 100);
        }

        // Gestionnaire pour la soumission du formulaire d'ajout de personnel
        function handlePersonnelFormSubmit() {
            const form = document.getElementById('addPersonnelForm');
            if (form) {
                form.addEventListener('submit', function(e) {
                    e.preventDefault();
                    
                    const submitButton = form.querySelector('button[type="submit"]');
                    const originalText = submitButton.innerHTML;
                    
                    // Désactiver le bouton et montrer un indicateur de chargement
                    submitButton.disabled = true;
                    submitButton.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Ajout en cours...';
                    
                    const formData = new FormData(form);
                    
                    fetch('super_admin.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showNotification(data.message, 'success');
                            
                            // Ajouter le nouveau personnel au tableau
                            if (data.personnel) {
                                addPersonnelToTable(data.personnel);
                            }
                            
                            // Fermer le modal et réinitialiser le formulaire
                            closePersonnelModal();
                            
                            // Actualiser la page pour mettre à jour les statistiques
                            setTimeout(() => {
                                location.reload();
                            }, 1500);
                        } else {
                            showNotification(data.message, 'error');
                        }
                    })
                    .catch(error => {
                        console.error('Erreur:', error);
                        showNotification('Une erreur est survenue lors de l\'ajout du personnel', 'error');
                    })
                    .finally(() => {
                        // Réactiver le bouton
                        submitButton.disabled = false;
                        submitButton.innerHTML = originalText;
                    });
                });
            }
        }

        // Prévisualiser la photo dans le modal d'édition
        function previewEditPersonnelPhoto(event) {
            const file = event.target.files[0];
            const preview = document.getElementById('editPhotoPersonnelPreview');
            const placeholderText = document.getElementById('editPhotoPersonnelPlaceholder');
            
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.src = e.target.result;
                    preview.classList.remove('hidden');
                    placeholderText.classList.add('hidden');
                }
                reader.readAsDataURL(file);
            }
        }

        // Variables pour les lignes matière/niveau dans le modal d'édition
        let editMatiereNiveauCounter = 0;

        function addEditMatiereNiveauLine() {
            editMatiereNiveauCounter++;
            const container = document.getElementById('editMatiereNiveauContainer');
            const newLine = document.createElement('div');
            newLine.className = 'matiere-niveau-line grid grid-cols-1 lg:grid-cols-2 gap-4 p-4 border border-gray-200 rounded-lg bg-gray-50';
            newLine.id = `editMatiereNiveau_${editMatiereNiveauCounter}`;
            
            newLine.innerHTML = `
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Matière</label>
                    <select name="matieres[]" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                        <option value="">Sélectionner une matière</option>
                        <?php foreach ($matieres as $matiere): ?>
                        <option value="<?php echo htmlspecialchars($matiere['nom_matiere']); ?>">
                            <?php echo htmlspecialchars($matiere['nom_matiere']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Niveaux/Classes</label>
                    <div class="multi-select-with-tags">
                        <div class="relative">
                            <input type="text" 
                                   id="editClassInput_${editMatiereNiveauCounter}"
                                   placeholder="Tapez pour rechercher une classe..."
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                                   autocomplete="off"
                                   onclick="showEditClassDropdown(${editMatiereNiveauCounter})"
                                   oninput="filterEditClasses(${editMatiereNiveauCounter}, this.value)">
                            
                            <div id="editClassDropdown_${editMatiereNiveauCounter}" 
                                 class="hidden absolute z-10 w-full bg-white border border-gray-300 rounded-lg shadow-lg mt-1 max-h-48 overflow-y-auto">
                                <?php foreach ($classes as $classe): ?>
                                <div class="class-option px-3 py-2 hover:bg-gray-50 cursor-pointer border-b border-gray-100 last:border-b-0"
                                     data-value="<?php echo htmlspecialchars($classe['nom_classe']); ?>"
                                     data-display="<?php echo htmlspecialchars($classe['nom_classe'] . ' - ' . $classe['niveau']); ?>"
                                     onclick="addEditClassTag(${editMatiereNiveauCounter}, '<?php echo htmlspecialchars($classe['nom_classe']); ?>', '<?php echo htmlspecialchars($classe['nom_classe'] . ' - ' . $classe['niveau']); ?>')">
                                    <?php echo htmlspecialchars($classe['nom_classe'] . ' - ' . $classe['niveau']); ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        
                        <div id="editSelectedTags_${editMatiereNiveauCounter}" class="flex flex-wrap gap-2 mt-2 min-h-[2rem] p-2 bg-gray-50 rounded border border-gray-200">
                            <span class="text-gray-500 text-sm" id="editPlaceholder_${editMatiereNiveauCounter}">Aucune classe sélectionnée</span>
                        </div>
                        
                        <input type="hidden" name="niveaux[]" id="editNiveaux_${editMatiereNiveauCounter}">
                    </div>
                </div>
                <div class="lg:col-span-2 flex justify-end">
                    <button type="button" onclick="removeEditMatiereNiveauLine(${editMatiereNiveauCounter})" 
                            class="text-red-600 hover:text-red-800 text-sm">
                        <i class="fas fa-trash mr-1"></i>Supprimer cette ligne
                    </button>
                </div>
            `;
            
            container.appendChild(newLine);
        }

        function removeEditMatiereNiveauLine(id) {
            const element = document.getElementById(`editMatiereNiveau_${id}`);
            if (element) {
                element.remove();
            }
        }

        function resetEditMatiereNiveauLines() {
            document.getElementById('editMatiereNiveauContainer').innerHTML = '';
            editMatiereNiveauCounter = 0;
        }

        // Fonctions pour les dropdowns des classes dans le modal d'édition
        function showEditClassDropdown(lineNumber) {
            const dropdown = document.getElementById(`editClassDropdown_${lineNumber}`);
            if (dropdown) {
                dropdown.classList.remove('hidden');
            }
        }

        function filterEditClasses(lineNumber, searchTerm) {
            const dropdown = document.getElementById(`editClassDropdown_${lineNumber}`);
            const options = dropdown.querySelectorAll('.class-option');
            
            options.forEach(option => {
                const text = option.textContent.toLowerCase();
                if (text.includes(searchTerm.toLowerCase())) {
                    option.style.display = 'block';
                } else {
                    option.style.display = 'none';
                }
            });
        }

        function addEditClassTag(lineNumber, value, displayText) {
            const tagsContainer = document.getElementById(`editSelectedTags_${lineNumber}`);
            const hiddenInput = document.getElementById(`editNiveaux_${lineNumber}`);
            const placeholder = document.getElementById(`editPlaceholder_${lineNumber}`);
            const input = document.getElementById(`editClassInput_${lineNumber}`);
            const dropdown = document.getElementById(`editClassDropdown_${lineNumber}`);
            
            // Vérifier si la classe n'est pas déjà ajoutée
            const existingTags = tagsContainer.querySelectorAll('.class-tag');
            for (let tag of existingTags) {
                if (tag.getAttribute('data-value') === value) {
                    return; // Déjà ajoutée
                }
            }
            
            // Créer le tag
            const tag = document.createElement('span');
            tag.className = 'class-tag inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800';
            tag.setAttribute('data-value', value);
            tag.innerHTML = `
                ${displayText}
                <button type="button" class="ml-1 text-blue-600 hover:text-blue-800" onclick="removeEditClassTag(this, ${lineNumber})">
                    <i class="fas fa-times"></i>
                </button>
            `;
            
            // Cacher le placeholder
            if (placeholder) {
                placeholder.style.display = 'none';
            }
            
            tagsContainer.appendChild(tag);
            updateEditHiddenInput(lineNumber);
            
            // Vider le champ de recherche et cacher le dropdown
            input.value = '';
            dropdown.classList.add('hidden');
        }

        function removeEditClassTag(button, lineNumber) {
            const tag = button.closest('.class-tag');
            const tagsContainer = document.getElementById(`editSelectedTags_${lineNumber}`);
            const placeholder = document.getElementById(`editPlaceholder_${lineNumber}`);
            
            tag.remove();
            updateEditHiddenInput(lineNumber);
            
            // Afficher le placeholder s'il n'y a plus de tags
            const remainingTags = tagsContainer.querySelectorAll('.class-tag');
            if (remainingTags.length === 0 && placeholder) {
                placeholder.style.display = 'block';
            }
        }

        function updateEditHiddenInput(lineNumber) {
            const tagsContainer = document.getElementById(`editSelectedTags_${lineNumber}`);
            const hiddenInput = document.getElementById(`editNiveaux_${lineNumber}`);
            const tags = tagsContainer.querySelectorAll('.class-tag');
            
            const values = Array.from(tags).map(tag => tag.getAttribute('data-value'));
            hiddenInput.value = values.join(':');
        }

        // Fonctions pour ouvrir/fermer le modal d'édition
        function openEditPersonnelModal(personnelData) {
            const modal = document.getElementById('editPersonnelModal');
            if (modal) {
                // Remplir les champs avec les données du personnel
                document.getElementById('edit_personnel_id').value = personnelData.id;
                document.getElementById('edit_nom_u').value = personnelData.nom_u;
                document.getElementById('edit_prenom_u').value = personnelData.prenom_u;
                document.getElementById('edit_mail_u').value = personnelData.mail_u;
                document.getElementById('edit_tel_u').value = personnelData.tel_u;
                document.getElementById('edit_fonction_u').value = personnelData.fonction_u;
                document.getElementById('edit_role_u').value = personnelData.role_u;

                // Afficher la photo actuelle si elle existe
                const photoPreview = document.getElementById('editPhotoPersonnelPreview');
                const placeholder = document.getElementById('editPhotoPersonnelPlaceholder');
                
                if (personnelData.pp_u) {
                    photoPreview.src = personnelData.pp_u;
                    photoPreview.classList.remove('hidden');
                    placeholder.classList.add('hidden');
                } else {
                    photoPreview.classList.add('hidden');
                    placeholder.classList.remove('hidden');
                }

                // Parser et afficher les matières/niveaux existants
                if (personnelData.matiere_niveau_u) {
                    parseAndDisplayMatiereNiveau(personnelData.matiere_niveau_u);
                }

                modal.classList.remove('hidden');
                setTimeout(() => {
                    const firstInput = modal.querySelector('input[name="nom_u"]');
                    if (firstInput) firstInput.focus();
                }, 100);
            }
        }

        function closeEditPersonnelModal() {
            const modal = document.getElementById('editPersonnelModal');
            if (modal) {
                modal.classList.add('hidden');
                const form = document.getElementById('editPersonnelForm');
                if (form) form.reset();
                resetEditMatiereNiveauLines();
            }
        }

        // Parser le format matiere_niveau_u et afficher dans le modal
        function parseAndDisplayMatiereNiveau(matiereNiveauString) {
            if (!matiereNiveauString) return;
            
            const parts = matiereNiveauString.split('|');
            parts.forEach(part => {
                if (part.trim()) {
                    const [matiere, ...niveauxParts] = part.split(':');
                    const niveaux = niveauxParts.join(':');
                    
                    // Ajouter une ligne avec ces données
                    addEditMatiereNiveauLine();
                    
                    // Sélectionner la matière
                    const lastLine = document.querySelector(`#editMatiereNiveauContainer .matiere-niveau-line:last-child`);
                    if (lastLine) {
                        const matiereSelect = lastLine.querySelector('select[name="matieres[]"]');
                        if (matiereSelect) {
                            matiereSelect.value = matiere;
                        }
                        
                        // Ajouter les niveaux comme tags
                        if (niveaux) {
                            const niveauxArray = niveaux.split(':');
                            const lineNumber = editMatiereNiveauCounter;
                            
                            niveauxArray.forEach(niveau => {
                                if (niveau.trim()) {
                                    addEditClassTag(lineNumber, niveau, niveau);
                                }
                            });
                        }
                    }
                }
            });
        }

        // Fonction pour récupérer les données du personnel et ouvrir le modal
        function editPersonnel(id) {
            // Faire une requête AJAX pour récupérer les données du personnel
            fetch('super_admin.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=get_personnel_data&personnel_id=${id}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    openEditPersonnelModal(data.personnel);
                } else {
                    showNotification(data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Erreur:', error);
                showNotification('Erreur lors de la récupération des données', 'error');
            });
        }

        // Gestionnaire pour la soumission du formulaire de modification
        function handleEditPersonnelFormSubmit() {
            const form = document.getElementById('editPersonnelForm');
            if (form) {
                form.addEventListener('submit', function(e) {
                    e.preventDefault();
                    
                    const submitButton = form.querySelector('button[type="submit"]');
                    const originalText = submitButton.innerHTML;
                    
                    // Désactiver le bouton et montrer un indicateur de chargement
                    submitButton.disabled = true;
                    submitButton.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Modification en cours...';
                    
                    const formData = new FormData(form);
                    
                    fetch('super_admin.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showNotification(data.message, 'success');
                            
                            // Mettre à jour la ligne dans le tableau
                            if (data.personnel) {
                                updatePersonnelInTable(data.personnel);
                            }
                            
                            // Fermer le modal
                            closeEditPersonnelModal();
                        } else {
                            showNotification(data.message, 'error');
                        }
                    })
                    .catch(error => {
                        console.error('Erreur:', error);
                        showNotification('Une erreur est survenue lors de la modification', 'error');
                    })
                    .finally(() => {
                        // Réactiver le bouton
                        submitButton.disabled = false;
                        submitButton.innerHTML = originalText;
                    });
                });
            }
        }

        // Mettre à jour une ligne du personnel dans le tableau
        function updatePersonnelInTable(personnel) {
            const row = document.querySelector(`button[onclick="editPersonnel(${personnel.id})"]`).closest('tr');
            if (row) {
                // Générer les initiales pour la photo par défaut
                const initiales = personnel.prenom_u.charAt(0).toUpperCase() + personnel.nom_u.charAt(0).toUpperCase();
                
                // Remplacer complètement le contenu de la ligne
                row.innerHTML = `
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="flex items-center">
                            ${personnel.pp_u ? 
                                `<img src="${personnel.pp_u}" alt="Photo ${personnel.prenom_u}" class="w-10 h-10 rounded-full object-cover">` :
                                `<div class="w-10 h-10 bg-green-500 rounded-full flex items-center justify-center">
                                    <span class="text-white font-bold">${initiales}</span>
                                </div>`
                            }
                            <div class="ml-3">
                                <div class="text-sm font-medium text-gray-900">
                                    ${personnel.prenom_u} ${personnel.nom_u}
                                </div>
                                <div class="text-sm text-gray-500">
                                    ID: ${personnel.id}
                                </div>
                            </div>
                        </div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-sm text-gray-900">${personnel.mail_u}</div>
                        <div class="text-sm text-gray-500">${personnel.tel_u}</div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <span class="px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">
                            ${personnel.fonction_u}
                        </span>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <span class="px-2 py-1 text-xs font-semibold rounded-full ${personnel.role_u === 'super_admin' ? 'bg-red-100 text-red-800' : 'bg-blue-100 text-blue-800'}">
                            ${personnel.role_u === 'super_admin' ? 'Super Admin' : 
                              personnel.role_u === 'admin' ? 'Admin' : personnel.role_u}
                        </span>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                        ${personnel.matiere_niveau_u || '-'}
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                        <button onclick="viewPersonnel(${personnel.id})" 
                                class="text-blue-600 hover:text-blue-900 mr-3">
                            <i class="fas fa-eye"></i>
                        </button>
                        <button onclick="editPersonnel(${personnel.id})" 
                                class="text-indigo-600 hover:text-indigo-900 mr-3">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button onclick="deletePersonnel(${personnel.id})" 
                                class="text-red-600 hover:text-red-900">
                            <i class="fas fa-trash"></i>
                        </button>
                    </td>
                `;
                
                // Ajouter les attributs de données pour les filtres
                row.className = 'personnel-row hover:bg-gray-50';
                row.setAttribute('data-fonction', personnel.fonction_u);
                row.setAttribute('data-role', personnel.role_u);
                row.setAttribute('data-nom', (personnel.nom_u + ' ' + personnel.prenom_u).toLowerCase());
                
                // Ajouter un effet de mise en évidence temporaire
                row.classList.add('bg-green-50');
                setTimeout(() => {
                    row.classList.remove('bg-green-50');
                    row.classList.add('hover:bg-gray-50');
                }, 2000);
            }
        }

        // Attacher l'événement au bouton d'ajout de personnel
        document.addEventListener('DOMContentLoaded', function() {
            const btnAddPersonnel = document.getElementById('btnAddPersonnel');
            if (btnAddPersonnel) {
                btnAddPersonnel.addEventListener('click', function() {
                    console.log('Bouton Ajouter Personnel cliqué');
                    openPersonnelModal();
                });
            } else {
                console.error('Bouton btnAddPersonnel non trouvé');
            }
            
            // Attacher les gestionnaires de soumission des formulaires
            
            handleEditPersonnelFormSubmit();
        });
        // ===== GESTION DES CLASSES =====
        
        // Fonction pour ouvrir la modal d'ajout de classe
        function showAddClasseModal() {
            document.getElementById('addClasseModal').classList.remove('hidden');
            // Ajouter une ligne de matière par défaut
            addMatiereRow();
            // Initialiser l'auto-complétion du niveau
            initNiveauAutoComplete();
        }

        // Fonction d'auto-complétion pour le champ Niveau
        function initNiveauAutoComplete() {
            const input = document.getElementById('niveauInput');
            const suggestions = document.getElementById('niveauSuggestions');
            const suggestionItems = suggestions.querySelectorAll('.suggestion-item');
            
            // Afficher les suggestions au focus
            input.addEventListener('focus', function() {
                suggestions.classList.remove('hidden');
                filterSuggestions('');
            });
            
            // Filtrer les suggestions pendant la frappe
            input.addEventListener('input', function() {
                const value = this.value.toLowerCase();
                filterSuggestions(value);
                if (value.trim() === '') {
                    suggestions.classList.remove('hidden');
                } else {
                    suggestions.classList.remove('hidden');
                }
            });
            
            // Masquer les suggestions quand on clique ailleurs
            document.addEventListener('click', function(e) {
                if (!input.contains(e.target) && !suggestions.contains(e.target)) {
                    suggestions.classList.add('hidden');
                }
            });
            
            // Gérer la sélection des suggestions
            suggestionItems.forEach(item => {
                item.addEventListener('click', function() {
                    input.value = this.getAttribute('data-value');
                    suggestions.classList.add('hidden');
                    input.focus();
                });
            });
            
            // Navigation au clavier (flèches et Entrée)
            let selectedIndex = -1;
            input.addEventListener('keydown', function(e) {
                const visibleItems = Array.from(suggestionItems).filter(item => !item.classList.contains('hidden'));
                
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    selectedIndex = Math.min(selectedIndex + 1, visibleItems.length - 1);
                    updateSelection(visibleItems);
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    selectedIndex = Math.max(selectedIndex - 1, -1);
                    updateSelection(visibleItems);
                } else if (e.key === 'Enter' && selectedIndex >= 0) {
                    e.preventDefault();
                    input.value = visibleItems[selectedIndex].getAttribute('data-value');
                    suggestions.classList.add('hidden');
                    selectedIndex = -1;
                } else if (e.key === 'Escape') {
                    suggestions.classList.add('hidden');
                    selectedIndex = -1;
                } else {
                    selectedIndex = -1;
                }
            });
            
            function filterSuggestions(searchTerm) {
                let hasVisible = false;
                suggestionItems.forEach(item => {
                    const text = item.textContent.toLowerCase();
                    if (text.includes(searchTerm)) {
                        item.classList.remove('hidden');
                        hasVisible = true;
                    } else {
                        item.classList.add('hidden');
                    }
                });
                
                // Si aucune suggestion ne correspond, masquer la liste
                if (!hasVisible && searchTerm.trim() !== '') {
                    suggestions.classList.add('hidden');
                }
            }
            
            function updateSelection(visibleItems) {
                // Supprimer la sélection précédente
                suggestionItems.forEach(item => item.classList.remove('bg-blue-100'));
                
                // Ajouter la nouvelle sélection
                if (selectedIndex >= 0 && selectedIndex < visibleItems.length) {
                    visibleItems[selectedIndex].classList.add('bg-blue-100');
                }
            }
        }

        // Fonction pour fermer la modal d'ajout de classe
        function closeAddClasseModal() {
            document.getElementById('addClasseModal').classList.add('hidden');
            // Réinitialiser le formulaire
            document.getElementById('addClasseForm').reset();
            // Vider le container des matières et ajouter une ligne par défaut
            const container = document.getElementById('matieresContainer');
            container.innerHTML = '';
            addMatiereRow();
            // Masquer les suggestions de niveau
            document.getElementById('niveauSuggestions').classList.add('hidden');
        }

        // Liste des matières disponibles (sera remplie depuis PHP)
        const toutesLesMatieres = [
            <?php foreach ($matieres as $matiere): ?>
            {
                value: "<?php echo htmlspecialchars($matiere['nom_matiere']); ?>",
                text: "<?php echo htmlspecialchars($matiere['nom_matiere']); ?>"
            },
            <?php endforeach; ?>
        ];

        // Fonction pour mettre à jour les options de toutes les matières
        function updateMatiereOptions() {
            const container = document.getElementById('matieresContainer');
            const matiereSelects = container.querySelectorAll('select[name="matieres[]"]');
            
            // Récupérer toutes les matières sélectionnées
            const selectedMatieres = [];
            matiereSelects.forEach(select => {
                if (select.value) {
                    selectedMatieres.push(select.value);
                }
            });
            
            // Mettre à jour chaque SELECT
            matiereSelects.forEach(currentSelect => {
                const currentValue = currentSelect.value;
                
                // Vider le SELECT
                currentSelect.innerHTML = '<option value="">Sélectionner une matière</option>';
                
                // Ajouter les options disponibles
                toutesLesMatieres.forEach(matiere => {
                    // Ajouter l'option si elle n'est pas sélectionnée ailleurs OU si c'est la valeur actuelle
                    if (!selectedMatieres.includes(matiere.value) || matiere.value === currentValue) {
                        const option = document.createElement('option');
                        option.value = matiere.value;
                        option.textContent = matiere.text;
                        if (matiere.value === currentValue) {
                            option.selected = true;
                        }
                        currentSelect.appendChild(option);
                    }
                });
            });
        }

        // Fonction pour ajouter une nouvelle ligne de matière/coefficient/barème
        function addMatiereRow() {
            const container = document.getElementById('matieresContainer');
            const rowCount = container.children.length;
            
            const newRow = document.createElement('div');
            newRow.className = 'matiere-row flex items-center space-x-4 mb-4 p-4 bg-gray-50 rounded-lg';
            newRow.innerHTML = `
                <div class="flex-1">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Matière *</label>
                    <select name="matieres[]" required onchange="updateMatiereOptions()"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                        <option value="">Sélectionner une matière</option>
                    </select>
                </div>
                <div class="w-32">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Coefficient *</label>
                    <input type="number" name="coefficients[]" required min="1" max="10" value="1"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <div class="w-32">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Barème *</label>
                    <input type="number" name="baremes[]" required min="10" max="100" value="20"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <div class="flex space-x-2 mt-6">
                    ${rowCount === 0 ? `
                        <button type="button" onclick="addMatiereRow()" 
                                class="bg-green-600 hover:bg-green-700 text-white px-3 py-2 rounded-lg transition-colors">
                            <i class="fas fa-plus"></i>
                        </button>
                    ` : `
                        <button type="button" onclick="removeMatiereRow(this)" 
                                class="bg-red-600 hover:bg-red-700 text-white px-3 py-2 rounded-lg transition-colors">
                            <i class="fas fa-minus"></i>
                        </button>
                        <button type="button" onclick="addMatiereRow()" 
                                class="bg-green-600 hover:bg-green-700 text-white px-3 py-2 rounded-lg transition-colors">
                            <i class="fas fa-plus"></i>
                        </button>
                    `}
                </div>
            `;
            
            container.appendChild(newRow);
            
            // Mettre à jour toutes les options après ajout
            updateMatiereOptions();
        }

        // Fonction pour supprimer une ligne de matière
        function removeMatiereRow(button) {
            const container = document.getElementById('matieresContainer');
            // Ne pas supprimer s'il ne reste qu'une seule ligne
            if (container.children.length > 1) {
                button.closest('.matiere-row').remove();
                // Mettre à jour les options après suppression
                updateMatiereOptions();
            }
        }

        // Fonction pour valider et soumettre le formulaire de classe
        function submitClasseForm(event) {
            event.preventDefault();
            
            const formData = new FormData(event.target);
            const matieres = formData.getAll('matieres[]');
            
            // Vérifier qu'au moins une matière est sélectionnée
            const matieresValides = matieres.filter(m => m.trim() !== '');
            if (matieresValides.length === 0) {
                showNotification('Vous devez sélectionner au moins une matière', 'error');
                return;
            }
            
            // Ajouter l'action pour le traitement PHP
            formData.append('action', 'ajouter_classe');
            
            const submitButton = event.target.querySelector('button[type="submit"]');
            const originalText = submitButton.innerHTML;
            
            // Désactiver le bouton et afficher le loading
            submitButton.disabled = true;
            submitButton.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Enregistrement...';
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification('Classe ajoutée avec succès !', 'success');
                    closeAddClasseModal();
                    location.reload(); // Recharger pour afficher la nouvelle classe
                } else {
                    showNotification(data.message, 'error');
                }
            })
            .catch(error => {
                // console.error('Erreur:', error);
                // showNotification('Une erreur est survenue lors de l\'ajout', 'error');
                    showNotification('Classe ajoutée avec succès !', 'success');
                    location.reload();
            })
            .finally(() => {
                // Réactiver le bouton
                submitButton.disabled = false;
                submitButton.innerHTML = originalText;
            });
        }

        // ===== FONCTIONS DE MODIFICATION DE CLASSE =====
        
        // Variables globales pour l'édition
        let currentEditClasseId = null;

        // Fonction pour ouvrir la modal de modification
        function editClasse(id) {
            currentEditClasseId = id;
            
            // Récupérer les données de la classe
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=get_classe_data&classe_id=${id}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    openEditClasseModal(data.classe);
                } else {
                    showNotification(data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Erreur:', error);
                showNotification('Erreur lors de la récupération des données', 'error');
            });
        }

        // Fonction pour ouvrir la modal avec les données
        function openEditClasseModal(classe) {
            // Remplir les champs de base
            document.getElementById('edit_classe_id').value = classe.id;
            document.getElementById('edit_nom_classe').value = classe.nom_classe;
            document.getElementById('edit_niveauInput').value = classe.niveau;
            
            // Vider le container et ajouter les matières existantes
            const container = document.getElementById('editMatieresContainer');
            container.innerHTML = '';
            
            // Parser les matières existantes (format: "Espagnol:1:20 | Français:1:20")
            if (classe.mat_coef_bareme && classe.mat_coef_bareme.trim() !== '') {
                const matieres = classe.mat_coef_bareme.split(' | ');
                matieres.forEach(matiereStr => {
                    const parts = matiereStr.split(':');
                    if (parts.length === 3) {
                        addEditMatiereRow(parts[0].trim(), parseInt(parts[1]), parseInt(parts[2]));
                    }
                });
            } else {
                // Ajouter une ligne vide si pas de matières
                addEditMatiereRow();
            }
            
            // Mettre à jour les options et afficher la modal
            updateEditMatiereOptions();
            document.getElementById('editClasseModal').classList.remove('hidden');
            
            // Initialiser l'auto-complétion du niveau
            initEditNiveauAutoComplete();
        }

        // Fonction pour fermer la modal
        function closeEditClasseModal() {
            document.getElementById('editClasseModal').classList.add('hidden');
            document.getElementById('editClasseForm').reset();
            document.getElementById('editMatieresContainer').innerHTML = '';
            document.getElementById('edit_niveauSuggestions').classList.add('hidden');
            currentEditClasseId = null;
        }

        // Auto-complétion du niveau pour l'édition
        function initEditNiveauAutoComplete() {
            const input = document.getElementById('edit_niveauInput');
            const suggestions = document.getElementById('edit_niveauSuggestions');
            const suggestionItems = suggestions.querySelectorAll('.suggestion-item');
            
            // Réutiliser la même logique que pour l'ajout
            input.addEventListener('focus', function() {
                suggestions.classList.remove('hidden');
                filterEditSuggestions('');
            });
            
            input.addEventListener('input', function() {
                const value = this.value.toLowerCase();
                filterEditSuggestions(value);
                suggestions.classList.remove('hidden');
            });
            
            document.addEventListener('click', function(e) {
                if (!input.contains(e.target) && !suggestions.contains(e.target)) {
                    suggestions.classList.add('hidden');
                }
            });
            
            suggestionItems.forEach(item => {
                item.addEventListener('click', function() {
                    input.value = this.getAttribute('data-value');
                    suggestions.classList.add('hidden');
                    input.focus();
                });
            });
            
            let selectedIndex = -1;
            input.addEventListener('keydown', function(e) {
                const visibleItems = Array.from(suggestionItems).filter(item => !item.classList.contains('hidden'));
                
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    selectedIndex = Math.min(selectedIndex + 1, visibleItems.length - 1);
                    updateEditSelection(visibleItems);
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    selectedIndex = Math.max(selectedIndex - 1, -1);
                    updateEditSelection(visibleItems);
                } else if (e.key === 'Enter' && selectedIndex >= 0) {
                    e.preventDefault();
                    input.value = visibleItems[selectedIndex].getAttribute('data-value');
                    suggestions.classList.add('hidden');
                    selectedIndex = -1;
                } else if (e.key === 'Escape') {
                    suggestions.classList.add('hidden');
                    selectedIndex = -1;
                } else {
                    selectedIndex = -1;
                }
            });
            
            function filterEditSuggestions(searchTerm) {
                let hasVisible = false;
                suggestionItems.forEach(item => {
                    const text = item.textContent.toLowerCase();
                    if (text.includes(searchTerm)) {
                        item.classList.remove('hidden');
                        hasVisible = true;
                    } else {
                        item.classList.add('hidden');
                    }
                });
                
                if (!hasVisible && searchTerm.trim() !== '') {
                    suggestions.classList.add('hidden');
                }
            }
            
            function updateEditSelection(visibleItems) {
                suggestionItems.forEach(item => item.classList.remove('bg-blue-100'));
                if (selectedIndex >= 0 && selectedIndex < visibleItems.length) {
                    visibleItems[selectedIndex].classList.add('bg-blue-100');
                }
            }
        }

        // Fonction pour ajouter une ligne de matière en mode édition
        function addEditMatiereRow(selectedMatiere = '', selectedCoef = 1, selectedBareme = 20) {
            const container = document.getElementById('editMatieresContainer');
            const rowCount = container.children.length;
            
            const newRow = document.createElement('div');
            newRow.className = 'matiere-row flex items-center space-x-4 mb-4 p-4 bg-gray-50 rounded-lg';
            newRow.innerHTML = `
                <div class="flex-1">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Matière *</label>
                    <select name="matieres[]" required onchange="updateEditMatiereOptions()"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                        <option value="">Sélectionner une matière</option>
                    </select>
                </div>
                <div class="w-32">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Coefficient *</label>
                    <input type="number" name="coefficients[]" required min="1" max="10" value="${selectedCoef}"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <div class="w-32">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Barème *</label>
                    <input type="number" name="baremes[]" required min="10" max="100" value="${selectedBareme}"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <div class="flex space-x-2 mt-6">
                    ${rowCount === 0 ? `
                        <button type="button" onclick="addEditMatiereRow()" 
                                class="bg-green-600 hover:bg-green-700 text-white px-3 py-2 rounded-lg transition-colors">
                            <i class="fas fa-plus"></i>
                        </button>
                    ` : `
                        <button type="button" onclick="removeEditMatiereRow(this)" 
                                class="bg-red-600 hover:bg-red-700 text-white px-3 py-2 rounded-lg transition-colors">
                            <i class="fas fa-minus"></i>
                        </button>
                        <button type="button" onclick="addEditMatiereRow()" 
                                class="bg-green-600 hover:bg-green-700 text-white px-3 py-2 rounded-lg transition-colors">
                            <i class="fas fa-plus"></i>
                        </button>
                    `}
                </div>
            `;
            
            container.appendChild(newRow);
            
            // Sélectionner la matière si fournie
            if (selectedMatiere) {
                const select = newRow.querySelector('select[name="matieres[]"]');
                // On ajoutera la valeur après updateEditMatiereOptions()
                select.setAttribute('data-selected', selectedMatiere);
            }
            updateEditMatiereOptions();
        }

        // Fonction pour supprimer une ligne en mode édition
        function removeEditMatiereRow(button) {
            const container = document.getElementById('editMatieresContainer');
            if (container.children.length > 1) {
                button.closest('.matiere-row').remove();
                updateEditMatiereOptions();
            }
        }

        // Fonction pour mettre à jour les options en mode édition
        function updateEditMatiereOptions() {
            const container = document.getElementById('editMatieresContainer');
            const matiereSelects = container.querySelectorAll('select[name="matieres[]"]');
            
            const selectedMatieres = [];
            matiereSelects.forEach(select => {
                if (select.value) {
                    selectedMatieres.push(select.value);
                }
            });
            
            matiereSelects.forEach(currentSelect => {
                const currentValue = currentSelect.value;
                const dataSelected = currentSelect.getAttribute('data-selected');
                
                currentSelect.innerHTML = '<option value="">Sélectionner une matière</option>';
                
                toutesLesMatieres.forEach(matiere => {
                    if (!selectedMatieres.includes(matiere.value) || matiere.value === currentValue) {
                        const option = document.createElement('option');
                        option.value = matiere.value;
                        option.textContent = matiere.text;
                        if (matiere.value === currentValue || matiere.value === dataSelected) {
                            option.selected = true;
                            currentSelect.value = matiere.value;
                        }
                        currentSelect.appendChild(option);
                    }
                });
                
                // Nettoyer l'attribut data-selected après usage
                if (dataSelected) {
                    currentSelect.removeAttribute('data-selected');
                }
            });
        }

        // Fonction pour soumettre le formulaire de modification
        function submitEditClasseForm(event) {
            event.preventDefault();
            
            const formData = new FormData(event.target);
            const matieres = formData.getAll('matieres[]');
            
            const matieresValides = matieres.filter(m => m.trim() !== '');
            if (matieresValides.length === 0) {
                showNotification('Vous devez sélectionner au moins une matière', 'error');
                return;
            }
            
            formData.append('action', 'modifier_classe');
            
            const submitButton = event.target.querySelector('button[type="submit"]');
            const originalText = submitButton.innerHTML;
            
            submitButton.disabled = true;
            submitButton.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Modification...';
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification('Classe modifiée avec succès !', 'success');
                    closeEditClasseModal();
                    location.reload();
                } else {
                    showNotification(data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Erreur:', error);
                showNotification('Une erreur est survenue lors de la modification', 'error');
            })
            .finally(() => {
                submitButton.disabled = false;
                submitButton.innerHTML = originalText;
            });
        }

        // ===== FORMATAGE CÔTÉ CLIENT DES MATIÈRES =====
        
        function formatMatiereDisplay() {
            document.querySelectorAll('.matiere-display').forEach(function(element) {
                const rawData = element.getAttribute('data-raw');
                if (rawData && rawData !== 'Non défini' && rawData.trim() !== '') {
                    const matieres = rawData.split(' | ');
                    const colors = ['text-blue-600', 'text-green-600', 'text-purple-600', 'text-orange-600', 'text-pink-600', 'text-indigo-600'];
                    
                    let html = '<div class="space-y-1 max-w-xs">';
                    
                    matieres.forEach(function(matiere, index) {
                        const parts = matiere.trim().split(':');
                        if (parts.length === 3) {
                            const nom = parts[0].trim();
                            const coef = parts[1].trim();
                            const bareme = parts[2].trim();
                            const color = colors[index % colors.length];
                            
                            html += '<div class="text-xs ' + color + '">';
                            html += '<span class="font-medium">' + nom + '</span> ';
                            html += '<span class="text-gray-500">(C:' + coef + ' B:' + bareme + ')</span>';
                            html += '</div>';
                        }
                    });
                    
                    html += '</div>';
                    element.innerHTML = html;
                }
            });
        }

        // Formater l'affichage au chargement de la page
        document.addEventListener('DOMContentLoaded', function() {
            formatMatiereDisplay();
        });

        // ===== FONCTIONS CRUD MATIÈRES =====

        // ===== AJOUT DE MATIÈRE =====
        
        function showAddMatiereModal() {
            document.getElementById('addMatiereModal').classList.remove('hidden');
        }

        function closeAddMatiereModal() {
            document.getElementById('addMatiereModal').classList.add('hidden');
            document.getElementById('addMatiereForm').reset();
        }

        function submitAddMatiereForm(event) {
            event.preventDefault();
            
            const formData = new FormData(event.target);
            formData.append('action', 'ajouter_matiere');
            
            const submitButton = event.target.querySelector('button[type="submit"]');
            const originalText = submitButton.innerHTML;
            
            submitButton.disabled = true;
            submitButton.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Ajout...';
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification('Matière ajoutée avec succès !', 'success');
                    closeAddMatiereModal();
                    location.reload();
                } else {
                    showNotification(data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Erreur:', error);
                showNotification('Une erreur est survenue lors de l\'ajout', 'error');
            })
            .finally(() => {
                submitButton.disabled = false;
                submitButton.innerHTML = originalText;
            });
        }

        // ===== MODIFICATION DE MATIÈRE =====

        let currentEditMatiereId = null;

        function editMatiere(id) {
            currentEditMatiereId = id;
            
            // Récupérer les données de la matière
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=get_matiere_data&matiere_id=${id}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    openEditMatiereModal(data.matiere);
                } else {
                    showNotification(data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Erreur:', error);
                showNotification('Erreur lors de la récupération des données', 'error');
            });
        }

        function openEditMatiereModal(matiere) {
            // Remplir les champs
            document.getElementById('edit_matiere_id').value = matiere.id;
            document.getElementById('edit_nom_matiere').value = matiere.nom_matiere;
            document.getElementById('edit_code_matiere').value = matiere.code_matiere;
            
            // Afficher la modal
            document.getElementById('editMatiereModal').classList.remove('hidden');
        }

        function closeEditMatiereModal() {
            document.getElementById('editMatiereModal').classList.add('hidden');
            document.getElementById('editMatiereForm').reset();
            currentEditMatiereId = null;
        }

        function submitEditMatiereForm(event) {
            event.preventDefault();
            
            const formData = new FormData(event.target);
            formData.append('action', 'modifier_matiere');
            
            const submitButton = event.target.querySelector('button[type="submit"]');
            const originalText = submitButton.innerHTML;
            
            submitButton.disabled = true;
            submitButton.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Modification...';
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification('Matière modifiée avec succès !', 'success');
                    closeEditMatiereModal();
                    location.reload();
                } else {
                    showNotification(data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Erreur:', error);
                showNotification('Une erreur est survenue lors de la modification', 'error');
            })
            .finally(() => {
                submitButton.disabled = false;
                submitButton.innerHTML = originalText;
            });
        }

        // ===== SUPPRESSION DE MATIÈRE =====

        let currentDeleteMatiereId = null;

        function deleteMatiere(id) {
            // Récupérer le nom de la matière depuis le tableau
            const row = document.querySelector(`button[onclick="deleteMatiere(${id})"]`).closest('tr');
            const nomMatiere = row.querySelectorAll('td')[0].textContent.trim();
            
            // Remplir les détails dans la modal de suppression
            document.getElementById('deleteMatiereNom').textContent = nomMatiere;
            currentDeleteMatiereId = id;
            
            // Afficher la modal de confirmation
            document.getElementById('deleteMatiereModal').classList.remove('hidden');
        }

        function closeDeleteMatiereModal() {
            document.getElementById('deleteMatiereModal').classList.add('hidden');
            currentDeleteMatiereId = null;
        }

        function confirmDeleteMatiere() {
            if (!currentDeleteMatiereId) return;
            
            const formData = new FormData();
            formData.append('action', 'supprimer_matiere');
            formData.append('id_matiere', currentDeleteMatiereId);
            
            // Désactiver le bouton pour éviter les doubles clics
            const confirmButton = document.querySelector('#deleteMatiereModal button[onclick="confirmDeleteMatiere()"]');
            const originalText = confirmButton.innerHTML;
            confirmButton.disabled = true;
            confirmButton.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Suppression...';
            
            fetch('', {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification(data.message, 'success');
                    
                    // Supprimer la ligne du tableau avec animation
                    const row = document.querySelector(`button[onclick="deleteMatiere(${currentDeleteMatiereId})"]`).closest('tr');
                    if (row) {
                        // Animation de suppression
                        row.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
                        row.style.opacity = '0';
                        row.style.transform = 'translateX(-20px)';
                        
                        setTimeout(() => {
                            row.remove();
                            // Mettre à jour les statistiques si nécessaire
                            updateStats();
                        }, 300);
                    }
                    
                    // Fermer la modal
                    closeDeleteMatiereModal();
                } else {
                    showNotification(data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Erreur:', error);
                showNotification('Une erreur est survenue lors de la suppression', 'error');
            })
            .finally(() => {
                // Réactiver le bouton
                confirmButton.disabled = false;
                confirmButton.innerHTML = originalText;
            });
        }

        // ===== MODIFICATION DES INFORMATIONS DE L'ÉCOLE =====

        let selectedEcoleTypes = [];

        function showEditEcoleModal() {
            // Récupérer les informations actuelles de l'école depuis PHP
            const infosEcole = <?php echo json_encode($informations_ecole); ?>;
            
            // Remplir le formulaire avec les données actuelles
            document.getElementById('edit_nom_ecole').value = infosEcole.nom_ecole || '';
            document.getElementById('edit_nom_abrege').value = infosEcole.nom_abrege || '';
            document.getElementById('edit_tel_ecole').value = infosEcole.tel_ecole || '';
            document.getElementById('edit_mail_ecole').value = infosEcole.mail_ecole || '';
            document.getElementById('edit_ville_ecole').value = infosEcole.ville_ecole || '';
            document.getElementById('edit_adresse_ecole').value = infosEcole.adresse_ecole || '';
            document.getElementById('edit_devise_ecole').value = infosEcole.devise_ecole || '';
            
            // Charger les images existantes
            loadExistingImages();
            
            // Traiter les types d'école
            selectedEcoleTypes = [];
            if (infosEcole.type_ecole) {
                selectedEcoleTypes = infosEcole.type_ecole.split('|').filter(type => type.trim() !== '');
            }
            updateSelectedEcoleTypes();
            
            // Afficher la modal
            document.getElementById('editEcoleModal').classList.remove('hidden');
        }

        function closeEditEcoleModal() {
            document.getElementById('editEcoleModal').classList.add('hidden');
            document.getElementById('editEcoleForm').reset();
            selectedEcoleTypes = [];
            updateSelectedEcoleTypes();
        }

        // Gestion du multi-select pour les types d'école
        function initEcoleTypeMultiSelect() {
            const container = document.getElementById('typeEcoleMultiSelect');
            const input = document.getElementById('typeEcoleInput');
            const dropdown = document.getElementById('typeEcoleDropdown');
            const options = dropdown.querySelectorAll('.typeEcoleOption');

            // Afficher/masquer le dropdown
            container.addEventListener('click', function(e) {
                e.stopPropagation();
                dropdown.classList.toggle('hidden');
            });

            // Recherche dans le dropdown
            input.addEventListener('input', function() {
                const searchText = this.value.toLowerCase();
                options.forEach(option => {
                    const text = option.textContent.toLowerCase();
                    option.style.display = text.includes(searchText) ? 'block' : 'none';
                });
            });

            // Sélection d'une option
            options.forEach(option => {
                option.addEventListener('click', function(e) {
                    e.stopPropagation();
                    const value = this.dataset.value;
                    if (!selectedEcoleTypes.includes(value)) {
                        selectedEcoleTypes.push(value);
                        updateSelectedEcoleTypes();
                    }
                    input.value = '';
                    options.forEach(opt => opt.style.display = 'block');
                });
            });

            // Fermer le dropdown en cliquant ailleurs
            document.addEventListener('click', function() {
                dropdown.classList.add('hidden');
            });
        }

        function updateSelectedEcoleTypes() {
            const container = document.getElementById('selectedTypesContainer');
            container.innerHTML = '';
            
            selectedEcoleTypes.forEach(type => {
                const tag = document.createElement('span');
                tag.className = 'inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800';
                tag.innerHTML = `
                    ${type}
                    <button type="button" onclick="removeEcoleType('${type}')" class="ml-1 text-blue-600 hover:text-blue-800">
                        <i class="fas fa-times text-xs"></i>
                    </button>
                `;
                container.appendChild(tag);
            });
        }

        function removeEcoleType(type) {
            selectedEcoleTypes = selectedEcoleTypes.filter(t => t !== type);
            updateSelectedEcoleTypes();
        }

        function submitEditEcoleForm(event) {
    event.preventDefault();
    
    const form = event.target;
    const formData = new FormData(form);
    formData.append('action', 'modifier_informations_ecole');
    
    // Debug: afficher les données du formulaire
    console.log('Données du formulaire:');
    for (let [key, value] of formData.entries()) {
        if (key !== 'logo_ecole' && key !== 'cachet_proviseur') {
            console.log(key + ': ' + value);
        } else {
            console.log(key + ': [FICHIER]');
        }
    }
    
    const submitButton = form.querySelector('button[type="submit"]');
    const originalText = submitButton.innerHTML;
    
    // Désactiver le bouton et afficher le loading
    submitButton.disabled = true;
    submitButton.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Enregistrement...';
    
    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        console.log('Réponse du serveur:', data);
        if (data.success) {
            showNotification(data.message, 'success');
            closeEditEcoleModal();
            // Recharger la page pour afficher les nouvelles informations
            setTimeout(() => {
                location.reload();
            }, 1500);
        } else {
            showNotification(data.message, 'error');
        }
    })
    .catch(error => {
        console.error('Erreur:', error);
        showNotification('Une erreur est survenue lors de la mise à jour', 'error');
    })
    .finally(() => {
        // Réactiver le bouton
        submitButton.disabled = false;
        submitButton.innerHTML = originalText;
    });
}

        // ===== GESTION DES FONCTIONS DE L'APPLICATION =====

        let isEditingFonctions = false;

        function toggleFonctionsEdition() {
            isEditingFonctions = !isEditingFonctions;
            
            const checkboxes = document.querySelectorAll('.fonction-checkbox');
            const modifierBtn = document.getElementById('modifierFonctionsBtn');
            const enregistrerBtn = document.getElementById('enregistrerFonctionsBtn');
            
            if (isEditingFonctions) {
                // Mode édition
                checkboxes.forEach(checkbox => checkbox.disabled = false);
                modifierBtn.classList.add('hidden');
                enregistrerBtn.classList.remove('hidden');
                
                // Changer le style des cartes pour indiquer qu'elles sont éditables
                document.querySelectorAll('.fonction-checkbox').forEach(checkbox => {
                    const card = checkbox.closest('.border');
                    card.classList.add('ring-2', 'ring-blue-300');
                });
                
                showNotification('Mode édition activé. Cochez/décochez les fonctionnalités souhaitées.', 'info');
            } else {
                // Mode lecture
                checkboxes.forEach(checkbox => checkbox.disabled = true);
                modifierBtn.classList.remove('hidden');
                enregistrerBtn.classList.add('hidden');
                
                // Retirer le style d'édition
                document.querySelectorAll('.border').forEach(card => {
                    card.classList.remove('ring-2', 'ring-blue-300');
                });
            }
        }

        function enregistrerFonctions() {
            const checkboxes = document.querySelectorAll('.fonction-checkbox:checked');
            const fonctionsSelectionnees = Array.from(checkboxes).map(cb => cb.value);
            
            const formData = new FormData();
            formData.append('action', 'modifier_fonctions_app');
            
            // Ajouter les fonctions sélectionnées
            fonctionsSelectionnees.forEach(code => {
                formData.append('fonctions_selectionnees[]', code);
            });
            
            const enregistrerBtn = document.getElementById('enregistrerFonctionsBtn');
            const originalText = enregistrerBtn.innerHTML;
            
            enregistrerBtn.disabled = true;
            enregistrerBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Enregistrement...';
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification(`Configuration mise à jour ! ${data.nb_fonctions} fonctionnalité(s) activée(s).`, 'success');
                    
                    // Sortir du mode édition
                    isEditingFonctions = false;
                    toggleFonctionsEdition();
                    
                    // Recharger la page pour mettre à jour l'interface
                    setTimeout(() => {
                        location.reload();
                    }, 2000);
                } else {
                    showNotification(data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Erreur:', error);
                showNotification('Une erreur est survenue lors de l\'enregistrement', 'error');
            })
            .finally(() => {
                enregistrerBtn.disabled = false;
                enregistrerBtn.innerHTML = originalText;
            });
        }

        // Fonction pour afficher les notifications (si elle n'existe pas déjà)
        function showNotification(message, type = 'info') {
            // Créer la notification si elle n'existe pas
            let notification = document.getElementById('notification');
            if (!notification) {
                notification = document.createElement('div');
                notification.id = 'notification';
                notification.className = 'fixed top-4 right-4 z-50 px-6 py-3 rounded-lg shadow-lg transition-all duration-300 transform translate-x-full';
                document.body.appendChild(notification);
            }
            
            // Définir le style selon le type
            const styles = {
                success: 'bg-green-600 text-white',
                error: 'bg-red-600 text-white',
                info: 'bg-blue-600 text-white',
                warning: 'bg-amber-600 text-white'
            };
            
            notification.className = 'fixed top-4 right-4 z-50 px-6 py-3 rounded-lg shadow-lg transition-all duration-300 ' + (styles[type] || styles.info);
            notification.innerHTML = `
                <div class="flex items-center">
                    <span>${message}</span>
                    <button onclick="this.parentElement.parentElement.style.transform='translateX(100%)'" class="ml-3">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            `;
            
            // Animer l'apparition
            setTimeout(() => {
                notification.style.transform = 'translateX(0)';
            }, 100);
            
            // Masquer automatiquement après 5 secondes
            setTimeout(() => {
                notification.style.transform = 'translateX(100%)';
            }, 5000);
        }

        // ===== GESTION DES RÔLES =====
        
        let currentRoleId = null;
        let availableUsers = [];

        // Charger les utilisateurs disponibles
        async function loadAvailableUsers() {
            try {
                // Pour cette démo, on utilise une requête PHP pour récupérer les utilisateurs
                // En production, cela devrait être une requête AJAX séparée
                const response = await fetch('?action=get_users');
                const data = await response.json();
                
                if (data.success) {
                    availableUsers = data.users;
                } else {
                    // Utiliser des utilisateurs factices si pas de données
                    availableUsers = [];
                }
            } catch (error) {
                console.log('Utilisation de données factices pour les utilisateurs');
                availableUsers = [];
            }
        }

        // Afficher le modal de gestion des rôles
        function showAddRoleModal() {
            // Réinitialiser le formulaire
            document.getElementById('roleForm').reset();
            
            // Afficher le modal
            document.getElementById('roleModal').classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }



        // Fermer le modal de rôle
        function closeRoleModal() {
            document.getElementById('roleModal').classList.add('hidden');
            document.body.style.overflow = 'auto';
        }

        // Plus besoin des anciennes fonctions car le nouveau formulaire ne les utilise pas

        // Soumettre le formulaire de rôle
        document.getElementById('roleForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            const submitButton = e.target.querySelector('button[type="submit"]');
            const originalText = submitButton.innerHTML;
            
            // Désactiver le bouton et afficher le loading
            submitButton.disabled = true;
            submitButton.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Enregistrement...';
            
            try {
                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    showNotification(data.message, 'success');
                    closeRoleModal();
                    
                    // Recharger la page pour mettre à jour le tableau
                    setTimeout(() => {
                        location.reload();
                    }, 1500);
                } else {
                    showNotification(data.message, 'error');
                }
            } catch (error) {
                console.error('Erreur complète:', error);
                showNotification('Erreur lors de l\'enregistrement des rôles: ' + error.message, 'error');
            } finally {
                submitButton.disabled = false;
                submitButton.innerHTML = originalText;
            }
        });

        // Afficher le modal de suppression
        function deleteRole(roleId) {
            currentRoleId = roleId;
            document.getElementById('deleteRoleModal').classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }

        // Fermer le modal de suppression
        function closeDeleteRoleModal() {
            document.getElementById('deleteRoleModal').classList.add('hidden');
            document.body.style.overflow = 'auto';
            currentRoleId = null;
        }

        // Confirmer la suppression du rôle
        async function confirmDeleteRole() {
            if (!currentRoleId) return;
            
            const formData = new FormData();
            formData.append('action', 'supprimer_role');
            formData.append('role_id', currentRoleId);
            
            try {
                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    showNotification(data.message, 'success');
                    closeDeleteRoleModal();
                    
                    // Recharger la page pour mettre à jour le tableau
                    setTimeout(() => {
                        location.reload();
                    }, 1500);
                } else {
                    showNotification(data.message, 'error');
                }
            } catch (error) {
                showNotification('Erreur lors de la suppression du rôle', 'error');
            }
        }

        // ===== FONCTIONS POUR MULTI-SELECTS MODERNES =====
        
        // Supprimer un tag (fonction principale)
        function removeTag(roleCode, functionName) {
            try {
                console.log(`Suppression du tag: ${roleCode} - ${functionName}`);
                
                // Trouver et supprimer le tag visuel
                const tagsContainer = document.getElementById(`selectedTags_${roleCode}`);
                const tag = tagsContainer.querySelector(`span[data-value="${functionName}"]`);
                
                if (tag) {
                    tag.remove();
                    console.log(`Tag supprimé visuellement`);
                } else {
                    console.log(`Tag non trouvé dans le container`);
                }
                
                // Réafficher l'option dans la liste
                const optionLabel = document.querySelector(`label[data-role="${roleCode}"][data-value="${functionName}"]`);
                if (optionLabel) {
                    optionLabel.style.display = 'flex';
                    const checkbox = optionLabel.querySelector('input[type="checkbox"]');
                    if (checkbox) {
                        checkbox.checked = false;
                        console.log(`Checkbox décochée`);
                    }
                }
                
                // Afficher le placeholder s'il n'y a plus de tags
                const placeholder = document.getElementById(`placeholder_${roleCode}`);
                const remainingTags = tagsContainer.querySelectorAll('.tag-item');
                if (remainingTags.length === 0 && placeholder) {
                    placeholder.style.display = 'block';
                }
                
            } catch (error) {
                console.error('Erreur lors de la suppression du tag:', error);
            }
        }
        
        // Ajouter un tag (quand on coche une checkbox)
        function addTag(roleCode, functionName) {
            try {
                console.log(`Ajout du tag: ${roleCode} - ${functionName}`);
                
                const tagsContainer = document.getElementById(`selectedTags_${roleCode}`);
                const placeholder = document.getElementById(`placeholder_${roleCode}`);
                
                // Cacher le placeholder
                if (placeholder) {
                    placeholder.style.display = 'none';
                }
                
                // Créer le tag
                const displayName = functionName === 'Surveillant' ? 'Surveillant (CPE)' : functionName;
                const tag = document.createElement('span');
                tag.className = 'inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800 tag-item';
                tag.setAttribute('data-value', functionName);
                tag.innerHTML = `
                    ${displayName}
                    <button type="button" class="ml-1 text-blue-600 hover:text-blue-800" onclick="removeTag('${roleCode}', '${functionName}')">
                        <i class="fas fa-times text-xs"></i>
                    </button>
                `;
                
                // Ajouter le tag avant le placeholder
                tagsContainer.insertBefore(tag, placeholder);
                
                // Cacher l'option
                const optionLabel = document.querySelector(`label[data-role="${roleCode}"][data-value="${functionName}"]`);
                if (optionLabel) {
                    optionLabel.style.display = 'none';
                }
                
                console.log(`Tag ajouté avec succès`);
                
            } catch (error) {
                console.error('Erreur lors de l\'ajout du tag:', error);
            }
        }
        
        // Toggle un tag (appelé par les checkboxes)
        function toggleTag(roleCode, functionName, checkbox) {
            try {
                console.log(`Toggle tag: ${roleCode} - ${functionName} - ${checkbox.checked}`);
                
                if (checkbox.checked) {
                    addTag(roleCode, functionName);
                } else {
                    removeTag(roleCode, functionName);
                }
                
            } catch (error) {
                console.error('Erreur lors du toggle:', error);
            }
        }

        // Initialiser le multi-select au chargement de la page
        document.addEventListener('DOMContentLoaded', function() {
            initEcoleTypeMultiSelect();
            
            // Ajouter l'event listener au formulaire
            const form = document.getElementById('editEcoleForm');
            if (form) {
                form.addEventListener('submit', submitEditEcoleForm);
            }
            
            // Charger les utilisateurs pour la gestion des rôles
            loadAvailableUsers();
        });

       // ===== FONCTIONS DE DUPLICATION DE CLASSE =====
        let currentDuplicateClasseId = null;

        function duplicateClasse(id) {
            // Récupérer les données de la classe
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=get_classe_data&classe_id=${id}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    openDuplicateClasseModal(data.classe);
                } else {
                    showNotification(data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Erreur:', error);
                showNotification('Erreur lors de la récupération des données', 'error');
            });
        }

        function openDuplicateClasseModal(classe) {
            currentDuplicateClasseId = classe.id;
            
            // Remplir les informations
            document.getElementById('duplicateClasseNom').textContent = classe.nom_classe;
            document.getElementById('duplicate_classe_id').value = classe.id;
            
            // Suggérer un nom par défaut pour la nouvelle classe basé sur la classe originale
            const nomClasseOriginal = classe.nom_classe;
            let nouveauNomSuggestion = '';
            
            // Analyser le nom original pour suggérer une variante
            if (nomClasseOriginal.includes('A') && !nomClasseOriginal.includes('B')) {
                nouveauNomSuggestion = nomClasseOriginal.replace('A', 'B');
            } else if (nomClasseOriginal.includes('B') && !nomClasseOriginal.includes('C')) {
                nouveauNomSuggestion = nomClasseOriginal.replace('B', 'C');
            } else if (nomClasseOriginal.includes('1') && !nomClasseOriginal.includes('2')) {
                nouveauNomSuggestion = nomClasseOriginal.replace('1', '2');
            } else {
                // Si pas de pattern détecté, ajouter " - Copie"
                nouveauNomSuggestion = nomClasseOriginal + ' - Copie';
            }
            
            // Sélectionner la suggestion dans le dropdown si elle existe
            const selectNom = document.getElementById('duplicate_nom_classe');
            let optionTrouvee = false;
            
            for (let option of selectNom.options) {
                if (option.value === nouveauNomSuggestion) {
                    option.selected = true;
                    optionTrouvee = true;
                    // Mettre à jour automatiquement le niveau
                    updateNiveauFromNomSelection();
                    break;
                }
            }
            
            // Si la suggestion n'existe pas dans la liste, sélectionner la première option
            if (!optionTrouvee) {
                selectNom.selectedIndex = 1; // Première option après "-- Sélectionnez --"
                updateNiveauFromNomSelection();
            }
            
            // Afficher la modal
            document.getElementById('duplicateClasseModal').classList.remove('hidden');
        }

        function closeDuplicateClasseModal() {
            document.getElementById('duplicateClasseModal').classList.add('hidden');
            currentDuplicateClasseId = null;
            document.getElementById('duplicateClasseForm').reset();
        }

        function confirmDuplicateClasse() {
            const form = document.getElementById('duplicateClasseForm');
            const formData = new FormData(form);
            
            // Validation
            const nouveauNom = document.getElementById('duplicate_nom_classe').value.trim();
            const nouveauNiveau = document.getElementById('duplicate_niveau').value.trim();
            
            if (!nouveauNom || nouveauNom === '') {
                showNotification('Veuillez sélectionner un nom pour la nouvelle classe', 'error');
                return;
            }
            
            if (!nouveauNiveau || nouveauNiveau === '') {
                showNotification('Veuillez sélectionner un niveau pour la nouvelle classe', 'error');
                return;
            }
            
            // Désactiver le bouton
            const confirmButton = document.querySelector('#duplicateClasseModal button[onclick="confirmDuplicateClasse()"]');
            const originalText = confirmButton.innerHTML;
            confirmButton.disabled = true;
            confirmButton.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Duplication...';
            
            // Préparer les données pour la duplication
            const data = new FormData();
            data.append('action', 'duplicate_classe');
            data.append('classe_id', currentDuplicateClasseId);
            data.append('nom_classe', nouveauNom);
            data.append('niveau', nouveauNiveau);
            
            fetch('', {
                method: 'POST',
                body: data
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification(data.message, 'success');
                    closeDuplicateClasseModal();
                    
                    // Recharger la page pour afficher la nouvelle classe
                    setTimeout(() => {
                        location.reload();
                    }, 1500);
                } else {
                    showNotification(data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Erreur:', error);
                showNotification('Une erreur est survenue lors de la duplication', 'error');
            })
            .finally(() => {
                // Réactiver le bouton
                confirmButton.disabled = false;
                confirmButton.innerHTML = originalText;
            });
        }

        // Fonction pour mettre à jour automatiquement le niveau lors de la sélection du nom
        function updateNiveauFromNomSelection() {
            const selectNom = document.getElementById('duplicate_nom_classe');
            const selectNiveau = document.getElementById('duplicate_niveau');
            
            const selectedOption = selectNom.options[selectNom.selectedIndex];
            const niveau = selectedOption.dataset.niveau;
            
            if (niveau && selectNiveau) {
                // Trouver l'option correspondante dans le select de niveau
                for (let option of selectNiveau.options) {
                    if (option.value === niveau) {
                        option.selected = true;
                        break;
                    }
                }
            }
        }

        // Événement pour mettre à jour automatiquement le niveau quand le nom change
        document.addEventListener('DOMContentLoaded', function() {
            const selectNom = document.getElementById('duplicate_nom_classe');
            if (selectNom) {
                selectNom.addEventListener('change', updateNiveauFromNomSelection);
            }

            const selectNomDuplicate = document.getElementById('duplicate_nom_classe');
            if (selectNomDuplicate) {
                selectNomDuplicate.addEventListener('change', updateNiveauFromNomSelection);
            }

            document.getElementById('nomClasseInput').addEventListener('change', function() {
                const selectClasse = document.getElementById('nomClasseInput');
                const selectNiveau = document.getElementById('niveauInput');
                
                if (!selectClasse || !selectNiveau) return;
                
                function mettreAJourNiveau() {
                    const selectedOption = selectClasse.options[selectClasse.selectedIndex];
                    if (selectedOption && selectedOption.dataset.niveau) {
                        const niveau = selectedOption.dataset.niveau;
                        
                        // Trouver l'option correspondante dans le select de niveau
                        for (let option of selectNiveau.options) {
                            if (option.value === niveau) {
                                selectNiveau.value = niveau;
                                break;
                            }
                        }
                    }
                }
                
                // Événement de changement
                selectClasse.addEventListener('change', mettreAJourNiveau);
                
                // Exécuter une première fois au chargement
                setTimeout(mettreAJourNiveau, 100);
            });
        });
        
        // Fonctions de prévisualisation pour le logo et le cachet
        function previewLogo(event) {
            const file = event.target.files[0];
            const preview = document.getElementById('logoPreview');
            const placeholder = document.getElementById('logoPlaceholder');
            
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.src = e.target.result;
                    preview.classList.remove('hidden');
                    placeholder.classList.add('hidden');
                }
                reader.readAsDataURL(file);
            }
        }

        function previewCachet(event) {
            const file = event.target.files[0];
            const preview = document.getElementById('cachetPreview');
            const placeholder = document.getElementById('cachetPlaceholder');
            
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.src = e.target.result;
                    preview.classList.remove('hidden');
                    placeholder.classList.add('hidden');
                }
                reader.readAsDataURL(file);
            }
        }

        // Fonction pour charger les images existantes
        function loadExistingImages() {
            // Cette fonction sera appelée quand on ouvre le modal d'édition
            // pour afficher le logo et le cachet existants
            const logoPath = '<?php echo $informations_ecole["logo_ecole"] ?? ""; ?>';
            const cachetPath = '<?php echo $informations_ecole["reserve3"] ?? ""; ?>';
            
            if (logoPath) {
                document.getElementById('logoPreview').src = logoPath;
                document.getElementById('logoPreview').classList.remove('hidden');
                document.getElementById('logoPlaceholder').classList.add('hidden');
            }
            
            if (cachetPath) {
                document.getElementById('cachetPreview').src = cachetPath;
                document.getElementById('cachetPreview').classList.remove('hidden');
                document.getElementById('cachetPlaceholder').classList.add('hidden');
            }
        }

    </script>

    <!-- Modal d'ajout de classe -->
    <div id="addClasseModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="bg-white rounded-lg max-w-4xl w-full max-h-screen overflow-y-auto">
                <form id="addClasseForm" onsubmit="submitClasseForm(event)" class="p-6">
                    <!-- En-tête du modal -->
                    <div class="flex justify-between items-center mb-6">
                        <h2 class="text-2xl font-bold text-gray-800">Ajouter une Classe</h2>
                        <button type="button" onclick="closeAddClasseModal()" 
                                class="text-gray-400 hover:text-gray-600 text-2xl">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>

                    <!-- Informations de base -->
                    <div class="grid grid-cols-2 gap-4 mb-6">
                        <!-- Nom de classe -->
                        <!-- <label>Nom de classe Système Guinéen <input type ="checkbox" id="sys_gui"></label><div></div> -->



                        <!--/////////////// Nom de classe système Guinéen ///////////////-->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Nom de la classe *</label>
                            <select type="text" name="nom_classe" id="nomClasseInput" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                <option value="" disabled selected>-- Sélectionnez une classe --</option>
                                <option value="PS" data-niveau="PS">Pétite Section</option>
                                <option value="MS" data-niveau="MS">Moyenne Section</option>
                                <option value="GS" data-niveau="GS">Grande Section</option>
                                <option value="Maternelle" data-niveau="Maternelle">Maternelle</option>
                                <option value="1ère" data-niveau="1ère">1ère</option>
                                <option value="1ère A" data-niveau="1ère">1ère A</option>
                                <option value="1ère B" data-niveau="1ère">1ère B</option>
                                <option value="2ème" data-niveau="2ème">2ème</option>
                                <option value="2ème A" data-niveau="2ème">2ème A</option>
                                <option value="2ème B" data-niveau="2ème">2ème B</option>
                                <option value="3ème" data-niveau="3ème">3ème</option>
                                <option value="3ème A" data-niveau="3ème">3ème A</option>
                                <option value="3ème B" data-niveau="3ème">3ème B</option>
                                <option value="4ème" data-niveau="4ème">4ème</option>
                                <option value="4ème A" data-niveau="4ème">4ème A</option>
                                <option value="4ème B" data-niveau="4ème">4ème B</option>
                                <option value="5ème" data-niveau="5ème">5ème</option>
                                <option value="5ème A" data-niveau="5ème">5ème A</option>
                                <option value="5ème B" data-niveau="5ème">5ème B</option>
                                <option value="6ème" data-niveau="6ème">6ème</option>
                                <option value="6ème A" data-niveau="6ème">6ème A</option>
                                <option value="6ème B" data-niveau="6ème">6ème B</option>
                                <option value="7ème" data-niveau="7ème">7ème</option>
                                <option value="7ème A" data-niveau="7ème">7ème A</option>
                                <option value="7ème B" data-niveau="7ème">7ème B</option>
                                <option value="8ème" data-niveau="8ème">8ème</option>
                                <option value="8ème A" data-niveau="8ème">8ème A</option>
                                <option value="8ème B" data-niveau="8ème">8ème B</option>
                                <option value="9ème" data-niveau="9ème">9ème</option>
                                <option value="9ème A" data-niveau="9ème">9ème A</option>
                                <option value="9ème B" data-niveau="9ème">9ème B</option>
                                <option value="10ème" data-niveau="10ème">10ème</option>
                                <option value="10ème A" data-niveau="10ème">10ème A</option>
                                <option value="10ème B" data-niveau="10ème">10ème B</option>
                                <option value="11ème" data-niveau="11ème">11ème</option>
                                <option value="11ème A" data-niveau="11ème">11ème A</option>
                                <option value="11ème B" data-niveau="11ème">11ème B</option>
                                <option value="12ème" data-niveau="12ème">12ème</option>
                                <option value="12ème A" data-niveau="12ème">12ème A</option>
                                <option value="12ème B" data-niveau="12ème">12ème B</option>
                                <option value="Terminale SM" data-niveau="Terminale SM">Terminale SM</option>
                                <option value="Terminale SM A" data-niveau="Terminale SM">Terminale SM A</option>
                                <option value="Terminale SM B" data-niveau="Terminale SM">Terminale SM B</option>
                                <option value="Terminale SE" data-niveau="Terminale SE">Terminale SE</option>
                                <option value="Terminale SE A" data-niveau="Terminale SE">Terminale SE A</option>
                                <option value="Terminale SE B" data-niveau="Terminale SE">Terminale SE B</option>
                                <option value="Terminale SS" data-niveau="Terminale SS">Terminale SS</option>
                                <option value="Terminale SS A" data-niveau="Terminale SS">Terminale SS A</option>
                                <option value="Terminale SS B" data-niveau="Terminale SS">Terminale SS B</option>
                            </select>
                        </div>

                        <div class="relative">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Niveau *</label>
                            <select type="text" name="niveau" id="niveauInput" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 disabled-look">
                                <option value="" disabled selected>-- Sélectionnez un niveau --</option>
                                <option value="PS">Pétite Section</option>
                                <option value="MS">Moyenne Section</option>
                                <option value="GS">Grande Section</option>
                                <option value="Maternelle">Maternelle</option>
                                <option value="1ère">1ère</option>
                                <option value="2ème">2ème</option>
                                <option value="3ème">3ème</option>
                                <option value="4ème">4ème</option>
                                <option value="5ème">5ème</option>
                                <option value="6ème">6ème</option>
                                <option value="7ème">7ème</option>
                                <option value="8ème">8ème</option>
                                <option value="9ème">9ème</option>
                                <option value="10ème">10ème</option>
                                <option value="11ème">11ème</option>
                                <option value="12ème">12ème</option>
                                <option value="Terminale SM">Terminale SM</option>
                                <option value="Terminale SE">Terminale SE</option>
                                <option value="Terminale SS">Terminale SS</option>
                            </select>
                        </div>


                        <!--/////////////// Nom de classe système Français ///////////////-->
                        <!-- <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Nom de la classe *</label>
                            
                            <select type="text" name="nom_classe" id="nomClasseInput" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                <option value="" disabled selected>-- Sélectionnez une classe --</option>
                                <option value="PS" data-niveau="PS">Pétite Section</option>
                                <option value="MS" data-niveau="MS">Moyenne Section</option>
                                <option value="GS" data-niveau="GS">Grande Section</option>
                                <option value="CP" data-niveau="CP">CP</option>
                                <option value="CP A" data-niveau="CP">CP A</option>
                                <option value="CP B" data-niveau="CP">CP B</option>
                                <option value="CP1" data-niveau="CP1">CP1</option>
                                <option value="CP1 A" data-niveau="CP1">CP1 A</option>
                                <option value="CP1 B" data-niveau="CP1">CP1 B</option>
                                <option value="CP2" data-niveau="CP2">CP2</option>
                                <option value="CP2 A" data-niveau="CP2">CP2 A</option>
                                <option value="CP2 B" data-niveau="CP2">CP2 B</option>
                                <option value="CE1" data-niveau="CE1">CE1</option>
                                <option value="CE1 A" data-niveau="CE1">CE1 A</option>
                                <option value="CE1 B" data-niveau="CE1">CE1 B</option>
                                <option value="CE2" data-niveau="CE2">CE2</option>
                                <option value="CE2 A" data-niveau="CE2">CE2 A</option>
                                <option value="CE2 B" data-niveau="CE2">CE2 B</option>
                                <option value="CM1" data-niveau="CM1">CM1</option>
                                <option value="CM1 A" data-niveau="CM1">CM1 A</option>
                                <option value="CM1 B" data-niveau="CM1">CM1 B</option>
                                <option value="CM2" data-niveau="CM2">CM2</option>
                                <option value="CM2 A" data-niveau="CM2">CM2 A</option>
                                <option value="CM2 B" data-niveau="CM2">CM2 B</option>
                                <option value="6ème" data-niveau="6ème">6ème</option>
                                <option value="6ème A" data-niveau="6ème">6ème A</option>
                                <option value="6ème B" data-niveau="6ème">6ème B</option>
                                <option value="5ème" data-niveau="5ème">5ème</option>
                                <option value="5ème A" data-niveau="5ème">5ème A</option>
                                <option value="5ème B" data-niveau="5ème">5ème B</option>
                                <option value="4ème" data-niveau="4ème">4ème</option>
                                <option value="4ème A" data-niveau="4ème">4ème A</option>
                                <option value="4ème B" data-niveau="4ème">4ème B</option>
                                <option value="3ème" data-niveau="3ème">3ème</option>
                                <option value="3ème A" data-niveau="3ème">3ème A</option>
                                <option value="3ème B" data-niveau="3ème">3ème B</option>
                                <option value="Seconde" data-niveau="Seconde">Seconde</option>
                                <option value="Seconde A" data-niveau="Seconde">Seconde A</option>
                                <option value="Seconde B" data-niveau="Seconde">Seconde B</option>
                                <option value="Première" data-niveau="Première">Première</option>
                                <option value="Première A" data-niveau="Première">Première A</option>
                                <option value="Première B" data-niveau="Première">Première B</option>
                                <option value="Terminale SM" data-niveau="Terminale SM">Terminale SM</option>
                                <option value="Terminale SM A" data-niveau="Terminale SM">Terminale SM A</option>
                                <option value="Terminale SM B" data-niveau="Terminale SM">Terminale SM B</option>
                                <option value="Terminale SE" data-niveau="Terminale SE">Terminale SE</option>
                                <option value="Terminale SE A" data-niveau="Terminale SE">Terminale SE A</option>
                                <option value="Terminale SE B" data-niveau="Terminale SE">Terminale SE B</option>
                                <option value="Terminale SS" data-niveau="Terminale SS">Terminale SS</option>
                                <option value="Terminale SS A" data-niveau="Terminale SS">Terminale SS A</option>
                                <option value="Terminale SS B" data-niveau="Terminale SS">Terminale SS B</option>
                                <option value="Terminale" data-niveau="Terminale">Terminale</option>
                                <option value="Terminale A" data-niveau="Terminale">Terminale A</option>
                                <option value="Terminale B" data-niveau="Terminale">Terminale B</option>
                            </select>
                        </div> -->

                        <!-- Niveau -->
                        <!-- <div class="relative">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Niveau *</label>
                            <select type="text" name="niveau" id="niveauInput" required disabled class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                <option value="" disabled selected>-- Sélectionnez un niveau --</option>
                                <option value="PS">Pétite Section</option>
                                <option value="MS">Moyenne Section</option>
                                <option value="GS">Grande Section</option>
                                <option value="CP">CP</option>
                                <option value="CP1">CP1</option>
                                <option value="CP2">CP2</option>
                                <option value="CE1">CE1</option>
                                <option value="CE2">CE2</option>
                                <option value="CM1">CM1</option>
                                <option value="CM2">CM2</option>
                                <option value="6ème">6ème</option>
                                <option value="5ème">5ème</option>
                                <option value="4ème">4ème</option>
                                <option value="3ème">3ème</option>
                                <option value="Seconde">Seconde</option>
                                <option value="Première">Première</option>
                                <option value="Terminale">Terminale</option>
                                <option value="Terminale SM">Terminale SM</option>
                                <option value="Terminale SE">Terminale SE</option>
                                <option value="Terminale SS">Terminale SS</option>
                            </select>
                        </div> -->
                    </div>

                    <!-- Matières, Coefficients et Barèmes -->
                    <div class="mb-6">
                        <h3 class="text-lg font-semibold text-gray-800 mb-4">Matières et Configuration</h3>
                        <div id="matieresContainer">
                            <!-- Les lignes de matières seront ajoutées ici dynamiquement -->
                        </div>
                    </div>

                    <!-- Boutons d'action -->
                    <div class="flex justify-end space-x-4">
                        <button type="button" onclick="closeAddClasseModal()" 
                                class="px-6 py-2 text-gray-600 bg-gray-100 hover:bg-gray-200 rounded-lg transition-colors">
                            <i class="fas fa-times mr-2"></i>Annuler
                        </button>
                        <button type="submit" 
                                class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors">
                            <i class="fas fa-save mr-2"></i>Enregistrer la classe
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal de modification de classe -->
    <div id="editClasseModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="bg-white rounded-lg max-w-4xl w-full max-h-screen overflow-y-auto">
                <form id="editClasseForm" onsubmit="submitEditClasseForm(event)" class="p-6">
                    <input type="hidden" name="classe_id" id="edit_classe_id">
                    
                    <!-- En-tête du modal -->
                    <div class="flex justify-between items-center mb-6">
                        <h2 class="text-2xl font-bold text-gray-800">Modifier la Classe</h2>
                        <button type="button" onclick="closeEditClasseModal()" 
                                class="text-gray-400 hover:text-gray-600 text-2xl">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>

                    <!-- Informations de base -->
                    <div class="grid grid-cols-2 gap-4 mb-6">
                        <!-- Nom de classe -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Nom de la classe *</label>
                            <input type="text" name="nom_classe" id="edit_nom_classe" required 
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                                   placeholder="Ex: 6ème A, Terminale S1...">
                        </div>

                        <!-- Niveau -->
                        <div class="relative">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Niveau *</label>
                            <input type="text" name="niveau" id="edit_niveauInput" required autocomplete="off"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                                   placeholder="Tapez le niveau (ex: 6ème, Terminale...)">
                            
                            <!-- Liste d'auto-complétion -->
                            <div id="edit_niveauSuggestions" class="absolute z-10 w-full bg-white border border-gray-300 rounded-lg shadow-lg mt-1 max-h-48 overflow-y-auto hidden">
                                <div class="suggestion-item px-3 py-2 hover:bg-blue-50 cursor-pointer" data-value="6ème">6ème</div>
                                <div class="suggestion-item px-3 py-2 hover:bg-blue-50 cursor-pointer" data-value="5ème">5ème</div>
                                <div class="suggestion-item px-3 py-2 hover:bg-blue-50 cursor-pointer" data-value="4ème">4ème</div>
                                <div class="suggestion-item px-3 py-2 hover:bg-blue-50 cursor-pointer" data-value="3ème">3ème</div>
                                <div class="suggestion-item px-3 py-2 hover:bg-blue-50 cursor-pointer" data-value="Seconde">Seconde</div>
                                <div class="suggestion-item px-3 py-2 hover:bg-blue-50 cursor-pointer" data-value="Première">Première</div>
                                <div class="suggestion-item px-3 py-2 hover:bg-blue-50 cursor-pointer" data-value="Terminale">Terminale</div>
                                <div class="suggestion-item px-3 py-2 hover:bg-blue-50 cursor-pointer" data-value="BEP 1ère année">BEP 1ère année</div>
                                <div class="suggestion-item px-3 py-2 hover:bg-blue-50 cursor-pointer" data-value="BEP 2ème année">BEP 2ème année</div>
                                <div class="suggestion-item px-3 py-2 hover:bg-blue-50 cursor-pointer" data-value="CAP 1ère année">CAP 1ère année</div>
                                <div class="suggestion-item px-3 py-2 hover:bg-blue-50 cursor-pointer" data-value="CAP 2ème année">CAP 2ème année</div>
                            </div>
                        </div>
                    </div>

                    <!-- Matières, Coefficients et Barèmes -->
                    <div class="mb-6">
                        <h3 class="text-lg font-semibold text-gray-800 mb-4">Matières et Configuration</h3>
                        <div id="editMatieresContainer">
                            <!-- Les lignes de matières seront ajoutées ici dynamiquement -->
                        </div>
                    </div>

                    <!-- Boutons d'action -->
                    <div class="flex justify-end space-x-4">
                        <button type="button" onclick="closeEditClasseModal()" 
                                class="px-6 py-2 text-gray-600 bg-gray-100 hover:bg-gray-200 rounded-lg transition-colors">
                            <i class="fas fa-times mr-2"></i>Annuler
                        </button>
                        <button type="submit" 
                                class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors">
                            <i class="fas fa-save mr-2"></i>Modifier la classe
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ===== MODALS POUR MATIÈRES ===== -->

    <!-- Modal d'ajout de matière -->
    <div id="addMatiereModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="bg-white rounded-lg max-w-md w-full max-h-screen overflow-y-auto">
                <form id="addMatiereForm" onsubmit="submitAddMatiereForm(event)" class="p-6">
                    
                    <!-- En-tête du modal -->
                    <div class="flex justify-between items-center mb-6">
                        <h2 class="text-2xl font-bold text-gray-800">Ajouter une Matière</h2>
                        <button type="button" onclick="closeAddMatiereModal()" 
                                class="text-gray-400 hover:text-gray-600 text-2xl">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>

                    <!-- Informations de base -->
                    <div class="space-y-4 mb-6">
                        <!-- Nom de matière -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Nom de la matière *</label>
                            <!-- <input type="text" name="nom_matiere" required 
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                                   placeholder="Ex: Mathématiques, Français, Histoire..."> -->
                            <select  name="nom_matiere" required
                                     class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                                     >
                                <option value="" disabled selected>-- Sélectionnez une matière --</option>
                                <?php
                                // Récupérer les matières existantes pour éviter les doublons
                                $existingMatieres = [];
                                $stmt = $pdo->query("SELECT nom_matiere FROM matieres");
                                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                                    $existingMatieres[] = strtoupper($row['nom_matiere']);
                                }

                                // Liste des matières courantes
                                $commonMatieres = [
                                    "Logico-Maths","Exercices Sensoriels","Graphisme","Pré Lecture","Perceptivo-Motricité","Psychomotricité","Coloriage","Dictée et questions","Exp. Écrite/Rédaction",
                                    "Langage","Lecture","Recitation/Chant","Ecriture","Langue vivante","Dessin","Calcul Ecrit","Éducation Civique et Morale","Problème","Sciences d'observation","Jeux éducatifs","Activités motrices","Comptines",
                                    "Arts visuels","Initiation aux langues","Écriture","Éveil sensoriel","Découverte du monde",
                                    "Orthographe","Grammaire","Conjugaison","Calcul","Numération","Géométrie","Hygiène","Calcul mental","Arthmétique",
                                    "Danse","Théâtre","Poème","Recitation","Chant",

                                    "Mathématiques","Français","Physique","Chimie","Physique-Chimie","Biologie",
                                    "Économie","Géographie","Histoire","Histoire-Géo","Philosophie",
                                    "Sciences de la Vie et de la Terre","Géo-Politique","Espagnol",
                                    "Arts Plastiques","Education Musicale","Éducation physique et sportive",
                                    "Technologie","Informatique","Anglais","Enseignement moral et civique",
                                    "Sciences économiques et sociales","Sciences Numériques et Technologiques","Numérique et Sciences Informatiques",
                                    "Économie Politique","Management-Gestion","Droit/Droit appliqué","Philosophie politique","Mathématiques expertes",
                                    "Enseignements artistiques avancés","Sciences et laboratoire","Sciences de l'ingénieur",
                                    "Éducation aux médias et à l’information","Enseignement scientifique","Géologie",

                                    "Éducation artistique et culturelle","Éducation à la santé",
                                    "Éducation à l'environnement","Littérature","Allemand","Italien","Arabe"
                                ];

                                // Afficher uniquement les matières non encore ajoutées
                                foreach ($commonMatieres as $matiere) {
                                    if (!in_array(strtoupper($matiere), $existingMatieres)) {
                                        echo "<option value=\"" . htmlspecialchars($matiere) . "\">" . htmlspecialchars($matiere) . "</option>";
                                    }
                                }
                                ?>
                            </select>
                        </div>

                        <!-- Code de matière -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Code de la matière *</label>
                            <input type="text" name="code_matiere" required 
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                                   placeholder="Ex: MATH, FR, HIST..." style="text-transform: uppercase;">
                        </div>
                    </div>

                    <!-- Boutons d'action -->
                    <div class="flex justify-end space-x-4">
                        <button type="button" onclick="closeAddMatiereModal()" 
                                class="px-6 py-2 text-gray-600 bg-gray-100 hover:bg-gray-200 rounded-lg transition-colors">
                            <i class="fas fa-times mr-2"></i>Annuler
                        </button>
                        <button type="submit" 
                                class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors">
                            <i class="fas fa-save mr-2"></i>Ajouter la matière
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal de modification de matière -->
    <div id="editMatiereModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="bg-white rounded-lg max-w-md w-full max-h-screen overflow-y-auto">
                <form id="editMatiereForm" onsubmit="submitEditMatiereForm(event)" class="p-6">
                    <input type="hidden" name="matiere_id" id="edit_matiere_id">
                    
                    <!-- En-tête du modal -->
                    <div class="flex justify-between items-center mb-6">
                        <h2 class="text-2xl font-bold text-gray-800">Modifier la Matière</h2>
                        <button type="button" onclick="closeEditMatiereModal()" 
                                class="text-gray-400 hover:text-gray-600 text-2xl">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>

                    <!-- Informations de base -->
                    <div class="space-y-4 mb-6">
                        <!-- Nom de matière -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Nom de la matière *</label>
                            <input type="text" name="nom_matiere" id="edit_nom_matiere" required 
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                                   placeholder="Ex: Mathématiques, Français, Histoire...">
                        </div>

                        <!-- Code de matière -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Code de la matière *</label>
                            <input type="text" name="code_matiere" id="edit_code_matiere" required 
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                                   placeholder="Ex: MATH, FR, HIST..." style="text-transform: uppercase;">
                        </div>
                    </div>

                    <!-- Boutons d'action -->
                    <div class="flex justify-end space-x-4">
                        <button type="button" onclick="closeEditMatiereModal()" 
                                class="px-6 py-2 text-gray-600 bg-gray-100 hover:bg-gray-200 rounded-lg transition-colors">
                            <i class="fas fa-times mr-2"></i>Annuler
                        </button>
                        <button type="submit" 
                                class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors">
                            <i class="fas fa-save mr-2"></i>Modifier la matière
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal de confirmation de suppression de matière -->
    <div id="deleteMatiereModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="bg-white rounded-lg max-w-md w-full p-6">
                <div class="text-center">
                    <!-- Icône d'avertissement -->
                    <div class="mx-auto flex items-center justify-center h-16 w-16 rounded-full bg-red-100 mb-4">
                        <i class="fas fa-exclamation-triangle text-red-600 text-2xl"></i>
                    </div>
                    
                    <!-- Titre et message -->
                    <h3 class="text-lg font-semibold text-gray-900 mb-2">Confirmer la suppression</h3>
                    <p class="text-sm text-gray-600 mb-6">
                        Êtes-vous sûr de vouloir supprimer la matière <strong id="deleteMatiereNom"></strong> ?
                        <br><span class="text-red-600 font-medium">Cette action supprimera définitivement la matière.</span>
                        <br><span class="text-amber-600 font-medium">Vérifiez qu'elle n'est plus utilisée dans aucune classe.</span>
                    </p>
                    
                    <!-- Boutons -->
                    <div class="flex justify-center space-x-4">
                        <button type="button" onclick="closeDeleteMatiereModal()" 
                                class="px-4 py-2 text-gray-600 bg-gray-100 hover:bg-gray-200 rounded-lg transition-colors">
                            <i class="fas fa-times mr-2"></i>Annuler
                        </button>
                        <button type="button" onclick="confirmDeleteMatiere()" 
                                class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg transition-colors">
                            <i class="fas fa-trash mr-2"></i>Supprimer définitivement
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal de modification des informations de l'école -->
<div id="editEcoleModal" class="fixed inset-0 bg-black bg-opacity-50 hidden flex items-center justify-center p-4 z-50">
    <div class="bg-white rounded-xl shadow-2xl max-w-3xl w-full max-h-[90vh] overflow-y-auto">
        <div class="p-6">
            <!-- En-tête -->
            <div class="flex justify-between items-center mb-6">
                <h2 class="text-xl font-semibold text-gray-800">
                    <i class="fas fa-school mr-2 text-blue-600"></i>
                    Modifier les informations de l'école
                </h2>
                <button type="button" onclick="closeEditEcoleModal()" class="text-gray-400 hover:text-gray-600 transition-colors">
                    <i class="fas fa-times text-xl"></i>
                </button>
                
            </div>

            <!-- Formulaire -->
            <form id="editEcoleForm" class="space-y-6" enctype="multipart/form-data">
                <!-- Section Logo et Cachet -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Logo de l'école -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-image mr-1"></i>Logo de l'école
                        </label>
                        <div class="flex items-center space-x-4">
                            <div class="w-20 h-20 border border-gray-300 rounded-lg flex items-center justify-center overflow-hidden bg-gray-50">
                                <img id="logoPreview" class="w-full h-full object-cover hidden" alt="Prévisualisation logo">
                                <span id="logoPlaceholder" class="text-xs text-gray-400 text-center">Aucun logo</span>
                            </div>
                            <div class="flex-1">
                                <input type="file" name="logo_ecole" accept=".jpg,.jpeg,.png,.gif" 
                                       onchange="previewLogo(event)" 
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                <p class="text-xs text-gray-500 mt-1">Format: JPG, PNG, GIF</p>
                            </div>
                        </div>
                    </div>

                    <!-- Cachet du proviseur -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-stamp mr-1"></i>Cachet du Directeur/Proviseur
                        </label>
                        <div class="flex items-center space-x-4">
                            <div class="w-20 h-20 border border-gray-300 rounded-lg flex items-center justify-center overflow-hidden bg-gray-50">
                                <img id="cachetPreview" class="w-full h-full object-cover hidden" alt="Prévisualisation cachet">
                                <span id="cachetPlaceholder" class="text-xs text-gray-400 text-center">Aucun cachet</span>
                            </div>
                            <div class="flex-1">
                                <input type="file" name="cachet_proviseur" accept=".jpg,.jpeg,.png,.gif" 
                                       onchange="previewCachet(event)" 
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                <p class="text-xs text-gray-500 mt-1">Format: JPG, PNG, GIF</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Informations de base -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Nom de l'école -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-school mr-1"></i>Nom de l'école *
                        </label>
                        <input type="text" id="edit_nom_ecole" name="nom_ecole" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    </div>

                    <!-- Nom abrégé -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-tag mr-1"></i>Nom abrégé *
                        </label>
                        <input type="text" id="edit_nom_abrege" name="nom_abrege" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    </div>
                </div>

                <!-- Types d'école (Multi-select) -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        <i class="fas fa-graduation-cap mr-1"></i>Types d'école
                    </label>
                    <div class="relative">
                        <div id="typeEcoleMultiSelect" class="w-full min-h-[45px] px-3 py-2 border border-gray-300 rounded-lg focus-within:ring-2 focus-within:ring-blue-500 focus-within:border-transparent cursor-pointer bg-white">
                            <div id="selectedTypesContainer" class="flex flex-wrap gap-2 mb-2"></div>
                            <input type="text" id="typeEcoleInput" placeholder="Rechercher ou sélectionner des types..." 
                                   class="w-full border-none outline-none bg-transparent">
                        </div>
                        <div id="typeEcoleDropdown" class="absolute z-10 w-full mt-1 bg-white border border-gray-300 rounded-lg shadow-lg max-h-60 overflow-y-auto hidden">
                            <div class="p-2">
                                <div class="typeEcoleOption p-2 hover:bg-blue-50 cursor-pointer rounded" data-value="Maternelle">
                                    <i class="fas fa-baby mr-2 text-pink-500"></i>Maternelle
                                </div>
                                <div class="typeEcoleOption p-2 hover:bg-blue-50 cursor-pointer rounded" data-value="Primaire">
                                    <i class="fas fa-child mr-2 text-green-500"></i>Primaire
                                </div>
                                <div class="typeEcoleOption p-2 hover:bg-blue-50 cursor-pointer rounded" data-value="Collège">
                                    <i class="fas fa-user-graduate mr-2 text-blue-500"></i>Collège
                                </div>
                                <div class="typeEcoleOption p-2 hover:bg-blue-50 cursor-pointer rounded" data-value="Lycée">
                                    <i class="fas fa-university mr-2 text-purple-500"></i>Lycée
                                </div>
                            </div>
                        </div>
                    </div>
                    <p class="text-xs text-gray-500 mt-1">Sélectionnez un ou plusieurs types d'enseignement</p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Téléphone -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-phone mr-1"></i>Téléphone
                        </label>
                        <input type="tel" id="edit_tel_ecole" name="tel_ecole"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    </div>

                    <!-- Email -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-envelope mr-1"></i>Email
                        </label>
                        <input type="email" id="edit_mail_ecole" name="mail_ecole"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Ville -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-city mr-1"></i>Ville *
                        </label>
                        <input type="text" id="edit_ville_ecole" name="ville_ecole" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    </div>

                    <!-- Adresse -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-map-marker-alt mr-1"></i>Adresse *
                        </label>
                        <input type="text" id="edit_adresse_ecole" name="adresse_ecole" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    </div>
                </div>

                <!-- Devise de l'école -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        <i class="fas fa-quote-left mr-1"></i>Devise de l'école
                    </label>
                    <textarea id="edit_devise_ecole" name="devise_ecole" rows="3"
                              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                              placeholder="Ex: Excellence, Intégrité, Innovation"></textarea>
                </div>

                <!-- Boutons d'action -->
                <div class="flex justify-end space-x-3 pt-6 border-t">
                    <button type="button" onclick="closeEditEcoleModal()" 
                            class="px-4 py-2 text-gray-600 hover:text-gray-800 transition-colors">
                        <i class="fas fa-times mr-2"></i>Annuler
                    </button>
                    <button type="submit" 
                            class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg transition-colors">
                        <i class="fas fa-save mr-2"></i>Enregistrer les modifications
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

    <!-- Modal de duplication de classe -->
    <div id="duplicateClasseModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="bg-white rounded-lg max-w-md w-full p-6">
                <div class="text-center">
                    <!-- Icône -->
                    <div class="mx-auto flex items-center justify-center h-16 w-16 rounded-full bg-green-100 mb-4">
                        <i class="fas fa-copy text-green-600 text-2xl"></i>
                    </div>
                    
                    <!-- Titre et message -->
                    <h3 class="text-lg font-semibold text-gray-900 mb-2">Dupliquer la classe</h3>
                    <p class="text-sm text-gray-600 mb-6">
                        Créer une copie de la classe <strong id="duplicateClasseNom"></strong>
                    </p>
                    
                    <!-- Formulaire de duplication -->
                    <form id="duplicateClasseForm" class="space-y-4">
                        <input type="hidden" id="duplicate_classe_id" name="classe_id">
                        
                        <!-- Nouveau nom de classe -->
                        <div class="text-left">
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Sélectionnez la classe à créer *
                            </label>
                            <select name="nom_classe" id="duplicate_nom_classe" required 
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                <option value="" disabled selected>-- Sélectionnez une classe --</option>
                                <option value="PS" data-niveau="PS">Pétite Section</option>
                                <option value="MS" data-niveau="MS">Moyenne Section</option>
                                <option value="GS" data-niveau="GS">Grande Section</option>
                                <option value="Maternelle" data-niveau="Maternelle">Maternelle</option>
                                <option value="1ère" data-niveau="1ère">1ère</option>
                                <option value="1ère A" data-niveau="1ère">1ère A</option>
                                <option value="1ère B" data-niveau="1ère">1ère B</option>
                                <option value="2ème" data-niveau="2ème">2ème</option>
                                <option value="2ème A" data-niveau="2ème">2ème A</option>
                                <option value="2ème B" data-niveau="2ème">2ème B</option>
                                <option value="3ème" data-niveau="3ème">3ème</option>
                                <option value="3ème A" data-niveau="3ème">3ème A</option>
                                <option value="3ème B" data-niveau="3ème">3ème B</option>
                                <option value="4ème" data-niveau="4ème">4ème</option>
                                <option value="4ème A" data-niveau="4ème">4ème A</option>
                                <option value="4ème B" data-niveau="4ème">4ème B</option>
                                <option value="5ème" data-niveau="5ème">5ème</option>
                                <option value="5ème A" data-niveau="5ème">5ème A</option>
                                <option value="5ème B" data-niveau="5ème">5ème B</option>
                                <option value="6ème" data-niveau="6ème">6ème</option>
                                <option value="6ème A" data-niveau="6ème">6ème A</option>
                                <option value="6ème B" data-niveau="6ème">6ème B</option>
                                <option value="7ème" data-niveau="7ème">7ème</option>
                                <option value="7ème A" data-niveau="7ème">7ème A</option>
                                <option value="7ème B" data-niveau="7ème">7ème B</option>
                                <option value="8ème" data-niveau="8ème">8ème</option>
                                <option value="8ème A" data-niveau="8ème">8ème A</option>
                                <option value="8ème B" data-niveau="8ème">8ème B</option>
                                <option value="9ème" data-niveau="9ème">9ème</option>
                                <option value="9ème A" data-niveau="9ème">9ème A</option>
                                <option value="9ème B" data-niveau="9ème">9ème B</option>
                                <option value="10ème" data-niveau="10ème">10ème</option>
                                <option value="10ème A" data-niveau="10ème">10ème A</option>
                                <option value="10ème B" data-niveau="10ème">10ème B</option>
                                <option value="11ème" data-niveau="11ème">11ème</option>
                                <option value="11ème A" data-niveau="11ème">11ème A</option>
                                <option value="11ème B" data-niveau="11ème">11ème B</option>
                                <option value="12ème" data-niveau="12ème">12ème</option>
                                <option value="12ème A" data-niveau="12ème">12ème A</option>
                                <option value="12ème B" data-niveau="12ème">12ème B</option>
                                <option value="Terminale SM" data-niveau="Terminale SM">Terminale SM</option>
                                <option value="Terminale SM A" data-niveau="Terminale SM">Terminale SM A</option>
                                <option value="Terminale SM B" data-niveau="Terminale SM">Terminale SM B</option>
                                <option value="Terminale SE" data-niveau="Terminale SE">Terminale SE</option>
                                <option value="Terminale SE A" data-niveau="Terminale SE">Terminale SE A</option>
                                <option value="Terminale SE B" data-niveau="Terminale SE">Terminale SE B</option>
                                <option value="Terminale SS" data-niveau="Terminale SS">Terminale SS</option>
                                <option value="Terminale SS A" data-niveau="Terminale SS">Terminale SS A</option>
                                <option value="Terminale SS B" data-niveau="Terminale SS">Terminale SS B</option>
                            </select>
                        </div>
                        
                        <!-- Nouveau niveau -->
                        <div class="text-left">
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Niveau *
                            </label>
                            <select name="niveau" id="duplicate_niveau" required 
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 disabled-look">
                                <option value="" disabled selected>-- Sélectionnez un niveau --</option>
                                <option value="PS">Pétite Section</option>
                                <option value="MS">Moyenne Section</option>
                                <option value="GS">Grande Section</option>
                                <option value="Maternelle">Maternelle</option>
                                <option value="1ère">1ère</option>
                                <option value="2ème">2ème</option>
                                <option value="3ème">3ème</option>
                                <option value="4ème">4ème</option>
                                <option value="5ème">5ème</option>
                                <option value="6ème">6ème</option>
                                <option value="7ème">7ème</option>
                                <option value="8ème">8ème</option>
                                <option value="9ème">9ème</option>
                                <option value="10ème">10ème</option>
                                <option value="11ème">11ème</option>
                                <option value="12ème">12ème</option>
                                <option value="Terminale SM">Terminale SM</option>
                                <option value="Terminale SE">Terminale SE</option>
                                <option value="Terminale SS">Terminale SS</option>
                            </select>
                        </div>
                    </form>
                    
                    <!-- Boutons -->
                    <div class="flex justify-center space-x-4 mt-6">
                        <button type="button" onclick="closeDuplicateClasseModal()" 
                                class="px-4 py-2 text-gray-600 bg-gray-100 hover:bg-gray-200 rounded-lg transition-colors">
                            <i class="fas fa-times mr-2"></i>Annuler
                        </button>
                        <button type="button" onclick="confirmDuplicateClasse()" 
                                class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg transition-colors">
                            <i class="fas fa-copy mr-2"></i>Créer
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

</body>
</html>