<?php
/**
 * Fichier gérant l'installation et désinstallation du plugin Souscription
 *
 * @plugin     Souscription
 * @copyright  2013
 * @author     Olivier Tétard
 * @licence    GNU/GPL
 * @package    SPIP\Souscription\Installation
 */

if (!defined('_ECRIRE_INC_VERSION')) return;


/**
 * Fonction d'installation et de mise à jour du plugin Souscription.
 *
 * @param string $nom_meta_base_version
 *     Nom de la meta informant de la version du schéma de données du plugin installé dans SPIP
 * @param string $version_cible
 *     Version du schéma de données dans ce plugin (déclaré dans paquet.xml)
 * @return void
 **/
function souscription_upgrade($nom_meta_base_version, $version_cible){
	$maj = array();

	$maj['create'] = array(
		array('maj_tables',	array('spip_souscriptions','spip_souscriptions_liens','spip_souscription_campagnes'))
	);
	$maj['0.1.0'] = array(
		array('sql_alter', "TABLE spip_souscriptions ADD informer_comite_local varchar(3) NOT NULL DEFAULT ''")
	);

	$maj['0.2.0'] = array(
		array('sql_alter', "TABLE spip_souscriptions ADD pays text NOT NULL DEFAULT ''")
	);

	$maj['0.3.0'] = array(
		array('sql_alter', "TABLE spip_souscriptions ADD telephone text NOT NULL DEFAULT ''")
	);

	$maj['0.4.0'] = array(
		array('sql_alter', "TABLE spip_souscription_campagnes ADD objectif_limiter varchar(3) NOT NULL DEFAULT ''")
	);

	$maj['0.5.0'] = array(
		array('sql_alter', "TABLE spip_souscription_campagnes ADD configuration_specifique varchar(3) NOT NULL DEFAULT ''"),
		array('sql_alter', "TABLE spip_souscription_campagnes ADD type_saisie varchar(255) NOT NULL DEFAULT ''"),
		array('sql_alter', "TABLE spip_souscription_campagnes ADD montants text NOT NULL DEFAULT ''")
	);

	$maj['0.6.0'] = array(array('maj_configuration_montants'));

	$maj['0.7.0'] = array(
		array('sql_alter', "TABLE spip_souscription_campagnes ADD abo_type_saisie varchar(255) NOT NULL DEFAULT ''"),
		array('sql_alter', "TABLE spip_souscription_campagnes ADD abo_montants text NOT NULL DEFAULT ''")
	);
	$maj['0.7.1'] = array(
		array('maj_tables',	array('spip_souscriptions_liens')),
		array('sql_alter', "TABLE spip_souscriptions CHANGE id_transaction id_transaction_echeance bigint(21) NOT NULL DEFAULT 0"),
		array('souscription_maj_liens_transactions'),
	);
	$maj['0.7.2'] = array(
		array('maj_tables',	array('spip_souscriptions')),
		array('sql_update','spip_souscriptions',array('date_echeance'=>'date_souscription','date_fin'=>'date_souscription')),
		array('souscription_maj_montants_date'),
	);
	$maj['0.8.3'] = array(
		array('maj_tables',	array('spip_souscriptions')),
	);
	$maj['0.8.4'] = array(
		array('souscription_maj_statut'),
	);

	include_spip('base/upgrade');
	maj_plugin($nom_meta_base_version, $version_cible, $maj);
}

function souscription_maj_statut(){
	// toutes les transactions ok liees a une souscription
	$ids = sql_allfetsel(
		"L.id_souscription",
		"spip_souscriptions_liens AS L JOIN spip_transactions AS T ON (L.objet=".sql_quote('transaction')." AND L.id_objet=T.id_transaction)",
		"T.statut=".sql_quote('ok')
	);
	#var_dump($ids);
	$ids = array_map('reset',$ids);
	sql_updateq("spip_souscriptions",array('statut'=>'ok'),"statut=".sql_quote('prepa')." AND ".sql_in('id_souscription',$ids));

	// toutes les souscriptions ok mais dont abo_statut='commande'
	// recalculer le montant_cumul
	$souscriptions = sql_allfetsel("*","spip_souscriptions","statut=".sql_quote('ok')." AND abo_statut=".sql_quote('commande'));
	if ($souscriptions){
		foreach($souscriptions as $souscription){
			$montants = sql_allfetsel(
				"T.montant",
				"spip_souscriptions_liens AS L JOIN spip_transactions AS T ON (L.objet=".sql_quote('transaction')." AND L.id_objet=T.id_transaction)",
				"T.statut=".sql_quote('ok')." AND L.id_souscription=".intval($souscription['id_souscription'])
			);
			$montants = array_map('reset',$montants);
			$montants = array_map('floatval',$montants);
			$cumul = round(array_sum($montants),2);
			$set = array(
				'abo_statut' => 'ok',
				'montant_cumul' => $cumul,
			);
			sql_updateq('spip_souscriptions',$set,'id_souscription='.intval($souscription['id_souscription']));
			spip_log("Corriger montant_cumul/abo_statut sur souscription ".$souscription['id_souscription'],"maj");
			if (time()>_TIME_OUT)
				return;
		}
	}

	// toutes les souscriptions dont date_echeance=date_souscription
	$souscriptions = sql_allfetsel("*","spip_souscriptions","abo_statut=".sql_quote('ok')." AND date_echeance=date_souscription");
	if ($souscriptions){
		foreach($souscriptions as $souscription){
			$trans = sql_allfetsel(
				"T.montant,T.date_transaction",
				"spip_souscriptions_liens AS L JOIN spip_transactions AS T ON (L.objet=".sql_quote('transaction')." AND L.id_objet=T.id_transaction)",
				"T.statut=".sql_quote('ok')." AND L.id_souscription=".intval($souscription['id_souscription']),
				'',
				'date_transaction DESC'
			);
			$montants = array_map('reset',$trans);
			$montants = array_map('floatval',$montants);
			$cumul = round(array_sum($montants),2);

			$last = reset($trans);
			$last = $last['date_transaction'];
			$datep15 = date('Y-m-d H:i:s',strtotime("+15 day",strtotime($last)));
			$prochaine_echeance = $souscription['date_echeance'];
			spip_log("souscription #".$souscription['id_souscription']." $prochaine_echeance vs $datep15","souscriptions_abos");
			// l'incrementer pour atteindre celle du mois prochain
			while($prochaine_echeance<$datep15){
				$prochaine_echeance = date('Y-m-d H:i:s',strtotime("+1 month",strtotime($prochaine_echeance)));
				spip_log("souscription #".$souscription['id_souscription']." echeance=echeance+1 month : $prochaine_echeance vs $datep15","souscriptions_abos");
			}

			$set = array(
				'abo_statut' => 'ok',
				'montant_cumul' => $cumul,
				'date_echeance' => $prochaine_echeance,
			);

			#var_dump($souscription);
			#var_dump($set);
			sql_updateq('spip_souscriptions',$set,'id_souscription='.intval($souscription['id_souscription']));
			spip_log("Corriger montant_cumul/abo_statut/date_echeance sur souscription ".$souscription['id_souscription'],"maj");

			if (time()>_TIME_OUT)
				return;
		}
	}

}

function souscription_maj_montants_date(){
	$res = sql_select("S.id_souscription,T.montant","spip_souscriptions AS S JOIN spip_transactions as T ON (T.id_transaction=S.id_transaction_echeance)","S.montant=".sql_quote(''));
	while ($row = sql_fetch($res)){
		sql_updateq("spip_souscriptions",array('montant'=>$row['montant']),'id_souscription='.intval($row['id_souscription']));
		if (time()>_TIME_OUT)
			return;
	}
}

function souscription_maj_liens_transactions(){

	$done = sql_allfetsel("DISTINCT id_souscription","spip_souscriptions_liens");
	$done = array_map('reset',$done);

	$res = sql_select("id_souscription,id_transaction_echeance","spip_souscriptions",sql_in('id_souscription',$done,"NOT"));
	while ($row = sql_fetch($res)){
		$ins = array(
			'id_souscription'=>$row['id_souscription'],
			'id_objet'=>$row['id_transaction_echeance'],
			'objet'=>'transaction',
		);
		sql_insertq("spip_souscriptions_liens",$ins);
		if (time()>_TIME_OUT)
			return;
	}

}

/* Fonction permettant de changer le format des montants globaux pour
 * le plugin souscription. Les montants étaient stockés sous la forme
 * d'un array() sérialisés. Il sont désormais stockés dans leur format
 * chaine de caractères. */
function maj_configuration_montants(){
	foreach (array('adhesion_montants', 'don_montants') as $cfg){
		$cle_cfg = "souscription/${cfg}";

		if (!function_exists("lire_config"))
			include_spip("inc/config");
		$montants_orig = lire_config($cle_cfg);

		$montants = "";
		foreach ($montants_orig as $prix => $description){
			$montants .= $prix . "|" . $description . "\n";
		}

		ecrire_config($cle_cfg, $montants);
	}
}

/**
 * Fonction de désinstallation du plugin Souscription.
 *
 * @param string $nom_meta_base_version
 *     Nom de la meta informant de la version du schéma de données du plugin installé dans SPIP
 * @return void
 **/
function souscription_vider_tables($nom_meta_base_version){

	sql_drop_table("spip_souscriptions");
	sql_drop_table("spip_souscription_campagnes");

	/* Nettoyer les versionnages et forums */
	sql_delete("spip_versions", sql_in("objet", array('souscription')));
	sql_delete("spip_versions_fragments", sql_in("objet", array('souscription')));
	sql_delete("spip_forum", sql_in("objet", array('souscription')));

	effacer_meta($nom_meta_base_version);
}

?>
