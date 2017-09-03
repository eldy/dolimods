<?php
/* Copyright (C) 2004-2017 Laurent Destailleur  <eldy@users.sourceforge.net>
 *
* This program is free software; you can redistribute it and/or modify
* it under the terms of the GNU General Public License as published by
* the Free Software Foundation; either version 3 of the License, or
* (at your option) any later version.
*
* This program is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
* GNU General Public License for more details.
*
* You should have received a copy of the GNU General Public License
* along with this program. If not, see <http://www.gnu.org/licenses/>.
*/

/**
 *       \file       htdocs/sellyoursaas/backoffice/newcustomerinstance.php
 *       \ingroup    sellyoursaas
 *       \brief      Page to create a new SaaS customer or instance
 */

// Load Dolibarr environment
$res=0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (! $res && ! empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) $res=@include($_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php");
// Try main.inc.php into web root detected using web root caluclated from SCRIPT_FILENAME
$tmp=empty($_SERVER['SCRIPT_FILENAME'])?'':$_SERVER['SCRIPT_FILENAME'];$tmp2=realpath(__FILE__); $i=strlen($tmp)-1; $j=strlen($tmp2)-1;
while($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i]==$tmp2[$j]) { $i--; $j--; }
if (! $res && $i > 0 && file_exists(substr($tmp, 0, ($i+1))."/main.inc.php")) $res=@include(substr($tmp, 0, ($i+1))."/main.inc.php");
if (! $res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i+1)))."/main.inc.php")) $res=@include(dirname(substr($tmp, 0, ($i+1)))."/main.inc.php");
// Try main.inc.php using relative path
if (! $res && file_exists("../../main.inc.php")) $res=@include("../../main.inc.php");
if (! $res && file_exists("../../../main.inc.php")) $res=@include("../../../main.inc.php");
if (! $res) die("Include of main fails");

require_once(DOL_DOCUMENT_ROOT."/comm/action/class/actioncomm.class.php");
require_once(DOL_DOCUMENT_ROOT."/contact/class/contact.class.php");
require_once(DOL_DOCUMENT_ROOT."/contrat/class/contrat.class.php");
require_once(DOL_DOCUMENT_ROOT."/core/lib/company.lib.php");
require_once(DOL_DOCUMENT_ROOT."/core/lib/date.lib.php");
require_once(DOL_DOCUMENT_ROOT."/core/class/html.formcompany.class.php");
require_once(DOL_DOCUMENT_ROOT."/core/class/html.formother.class.php");
dol_include_once("/sellyoursaas/core/lib/dolicloud.lib.php");
dol_include_once("/sellyoursaas/backoffice/lib/refresh.lib.php");
dol_include_once('/sellyoursaas/class/dolicloudcustomernew.class.php');
dol_include_once('/sellyoursaas/class/cdolicloudplans.class.php');

$langs->load("admin");
$langs->load("companies");
$langs->load("users");
$langs->load("other");
$langs->load("commercial");
$langs->load("bills");
$langs->load("sellyoursaas@sellyoursaas");

$mesg=''; $error=0; $errors=array();

$action		= (GETPOST('action','alpha') ? GETPOST('action','alpha') : 'view');
$confirm	= GETPOST('confirm','alpha');
$backtopage = GETPOST('backtopage','alpha');
$id			= GETPOST('id','int');
$instanceoldid= GETPOST('instanceoldid','alpha');
$ref        = GETPOST('ref','alpha');
$refold     = GETPOST('refold','alpha');
$date_registration  = dol_mktime(0, 0, 0, GETPOST("date_registrationmonth",'int'), GETPOST("date_registrationday",'int'), GETPOST("date_registrationyear",'int'), 1);
$date_endfreeperiod = dol_mktime(0, 0, 0, GETPOST("endfreeperiodmonth",'int'), GETPOST("endfreeperiodday",'int'), GETPOST("endfreeperiodyear",'int'), 1);
if (empty($date_endfreeperiod) && ! empty($date_registration)) $date_endfreeperiod=$date_registration+15*24*3600;

$emailtocreate=GETPOST('emailtocreate')?GETPOST('emailtocreate'):GETPOST('email');
$instancetocreate=GETPOST('instancetocreate')?GETPOST('instancetocreate'):'xxx.yyy.'.$conf->global->SELLYOURSAAS_MAIN_DOMAIN_NAME;

$error = 0; $errors = array();


// For old data
$db2=getDoliDBInstance('mysqli', $conf->global->DOLICLOUD_DATABASE_HOST, $conf->global->DOLICLOUD_DATABASE_USER, $conf->global->DOLICLOUD_DATABASE_PASS, $conf->global->DOLICLOUD_DATABASE_NAME, $conf->global->DOLICLOUD_DATABASE_PORT);
if ($db2->error)
{
	dol_print_error($db2,"host=".$conf->db->host.", port=".$conf->db->port.", user=".$conf->db->user.", databasename=".$conf->db->name.", ".$db2->error);
	exit;
}
$dolicloudcustomer = new DoliCloudCustomerNew($db,$db2);



// Security check
$user->rights->sellyoursaas->sellyoursaas->delete = $user->rights->sellyoursaas->sellyoursaas->write;
$result = restrictedArea($user, 'sellyoursaas', 0, '','sellyoursaas');

// Initialize technical object to manage hooks of page. Note that conf->hooks_modules contains array array
include_once(DOL_DOCUMENT_ROOT.'/core/class/hookmanager.class.php');
$hookmanager=new HookManager($db);

$object=new Societe($db);

if (GETPOST('loadthirdparty')) $action='create2';
if (GETPOST('add')) $action='add';


/*
 *	Actions
 */

$parameters=array('id'=>$id, 'objcanvas'=>$objcanvas);
$reshook=$hookmanager->executeHooks('doActions',$parameters,$object,$action);    // Note that $action and $object may have been modified by some hooks

if (empty($reshook))
{
	// Cancel
	if (GETPOST("cancel") && ! empty($backtopage))
	{
		header("Location: ".$backtopage);
		exit;
	}

	if (GETPOST('loadthirdparty'))
	{
		$result = $object->fetch(GETPOST('thirdparty_id'), '', '', '','','','','','', GETPOST('email'));

		// If not found, we fill the record with data from old v1 mirror table
		if (empty($object->id))
		{
			$sql='SELECT rowid FROM '.MAIN_DB_PREFIX."dolicloud_customers WHERE email = '".$db->escape(GETPOST('email'))."'";
			$resql=$db->query($sql);
			if ($resql)
			{
				if ($obj = $db->fetch_object($resql))
				{
					$dolicloudcustomer->fetch($obj->rowid);

					if (! empty($dolicloudcustomer->id))
					{
						$object->name = $dolicloudcustomer->getFullName($langs);
						$object->email = $dolicloudcustomer->email;
					}
				}
			}
			else
			{
				dol_print_error($db);
			}
		}
	}

	// Add customer
	if ($action == 'add' && $user->rights->sellyoursaas->sellyoursaas->write)
	{
		$db->begin();

		$object=new Societe($db);

		if (! empty($canvas)) $object->canvas=$canvas;

		$instancetocreate = GETPOST('instancetocreate','alpha');
		$productidtocreate = GETPOST('producttocreate','alpha');
		$thirdpartyidselected = GETPOST('thirdpartyidselected','int');


		// Search info old v1 database to find more information
		$result = $dolicloudcustomer->fetch(0, $instancetocreate);

		if ($thirdpartyidselected > 0)
		{
			$object->fetch($thirdpartyidselected);

			// Set flag client if not set
			$object->client |= 1;

			$checkinstance=0;
			if (preg_match('/\.on\./', $instancetocreate))   { $checkinstance=1; $object->array_options['options_dolicloud']='yesv1'; }
			if (preg_match('/\.with\./', $instancetocreate)) { $checkinstance=1; $object->array_options['options_dolicloud']='yesv1'; }

			if (! $checkinstance)
			{
				$error++;
				setEventMEssages($langs->trans("ErrorBadValueForInstance"), null, 'errors');
				$action = 'create2';
			}
			else
			{
				$object->update($object->id, $user);

				if (! $error && ($conf->global->SELLYOURSAAS_DEFAULT_CUSTOMER_CATEG > 0))
				{
					$custcats = array($conf->global->SELLYOURSAAS_DEFAULT_CUSTOMER_CATEG);
					$object->setCategories($custcats, 'customer');
				}
			}
		}
		else
		{
			// Create customer

			$object->name	= GETPOST('nametocreate');
			$object->email	= GETPOST('emailtocreate');
			$object->mode_reglement_id = GETPOST('mode_reglement_id','int');

			$checkinstance=0;
			if (preg_match('/\.on\./', $instancetocreate))   { $checkinstance=1; $object->array_options['options_dolicloud']='yesv1'; }
			if (preg_match('/\.with\./', $instancetocreate)) { $checkinstance=1; $object->array_options['options_dolicloud']='yesv1'; }

			if (! $checkinstance)
			{
				$error++;
				setEventMEssages($langs->trans("ErrorBadValueForInstance"), null, 'errors');
				$action = 'create2';
			}

			if ($dolicloudcustomer->id > 0)
			{
				if (empty($object->name)) $object->name = $dolicloudcustomer->organization;
				$object->client=1;
				$object->code_client=-1;
				$object->name_alias = $dolicloudcustomer->getFullName($langs);
				$object->address = $dolicloudcustomer->address;
				$object->zip = $dolicloudcustomer->zip;
				$object->town = $dolicloudcustomer->town;
				//$object->country_id = $dolicloudcustomer->address;
				$object->phone = $dolicloudcustomer->phone;
				$object->tva_intra=$dolicloudcustomer->vat_number;
				$locale=$dolicloudcustomer->locale;
				if ($locale)
				{
					$localearray=explode('_',$locale);
					$object->default_lang=$localearray[0].'_'.strtoupper($localearray[1]?$localearray[1]:$localearray[0]);
				}
				$object->array_options['options_date_registration']=$dolicloudcustomer->date_registration;
				$object->array_options['options_partner']=$dolicloudcustomer->partner;
				if ($dolicloudcustomer->status == 'ACTIVE') $object->status = 1;
				else $object->status = 0;

				$object->ref_ext = '';
			}

			if (empty($object->name)) $object->name = $object->email;

			/*
			if (empty($_POST["instance"]) || empty($_POST["organization"]) || empty($_POST["plan"]) || empty($_POST["email"]))
			{
				$error++; $errors[]=$langs->trans("ErrorFieldRequired",$langs->transnoentitiesnoconv("Instance").",".$langs->transnoentitiesnoconv("Organization").",".$langs->transnoentitiesnoconv("Plan").",".$langs->transnoentitiesnoconv("EMail"));
				$action = 'create';
			}*/

			if (! $error)
			{
				$id =  $object->create($user);
				if ($id <= 0)
				{
					$error++;
					setEventMessages('', array_merge($errors,($object->error?array($object->error):$object->errors)), 'errors');
					$action = 'create2';
				}

				if (! $error && ($conf->global->SELLYOURSAAS_DEFAULT_CUSTOMER_CATEG > 0))
				{
					$custcats = array($conf->global->SELLYOURSAAS_DEFAULT_CUSTOMER_CATEG);
					$object->setCategories($custcats, 'customer');
				}

				$thirdpartyidselected = $id;
			}
		}

		// Now we create new contract/instance
		if (! $error && $thirdpartyidselected > 0)
		{
			$contract = new Contrat($db);

			$contract->ref_customer = $instancetocreate;
			$contract->date_contrat = dol_now();
			$contract->socid=$thirdpartyidselected;
			$contract->commercial_suivi_id = $user->id;
			$contract->commercial_signature_id = $user->id;
			/*$sql = "SELECT rowid, statut, ref, fk_soc, mise_en_service as datemise,";
			$sql.= " ref_supplier, ref_customer,";
			$sql.= " ref_ext,";
			$sql.= " fk_user_mise_en_service, date_contrat as datecontrat,";
			$sql.= " fk_user_author, fin_validite, date_cloture,";
			$sql.= " fk_projet,";
			$sql.= " fk_commercial_signature, fk_commercial_suivi,";
			$sql.= " note_private, note_public, model_pdf, extraparams";
			$sql.= " FROM ".MAIN_DB_PREFIX."contrat";
			$sql.= " WHERE ref_ext='".$db->escape($ref)."'";
			$sql.= " AND entity IN (".getEntity('contract', 0).")";
			$sql.= " AND statut = 1";*/

			if ($dolicloudcustomer->id > 0)
			{
				$contract->array_options['options_date_registration']=$dolicloudcustomer->date_registration;
				$contract->array_options['options_date_endfreeperiod']=$dolicloudcustomer->date_endfreeperiod;

				$contract->array_options['options_plan']       =$dolicloudcustomer->plan;
				$contract->array_options['options_hostname_os']=$dolicloudcustomer->hostname_web;
				$contract->array_options['options_username_os']=$dolicloudcustomer->username_web;
				$contract->array_options['options_password_os']=$dolicloudcustomer->password_web;
				$contract->array_options['options_hostname_db']=$dolicloudcustomer->hostname_db;
				$contract->array_options['options_database_db']=$dolicloudcustomer->database_db;
				$contract->array_options['options_port_db']    =$dolicloudcustomer->port_db?$dolicloudcustomer->port_db:3306;
				$contract->array_options['options_username_db']=$dolicloudcustomer->username_db;
				$contract->array_options['options_password_db']=$dolicloudcustomer->password_db;
				$contract->array_options['fileauthorizekey']   =$dolicloudcustomer->fileauthorizekey;
				$contract->array_options['filelock']           =$dolicloudcustomer->filelock;
			}

			if (! empty($contract->array_options['options_hostname_db']) && ! empty($contract->array_options['options_database_db']))
			{
				// Scan remote instance to get fresh data
				$result = refreshContract($contract);

				if ($result['error'])
				{
					$error++;
					setEventMessages($result['error'], null, 'errors');
				}
				else
				{
					$contract->array_options['options_nb_user'] = $result['nb_users'];
					$contract->array_options['options_nb_gb'] = $result['nb_gb'];
				}
			}

			/*var_dump($contract->array_options);
			var_dump($instancetocreate);
			var_dump($productidtocreate);
			var_dump($thirdpartyidselected);
			exit;*/
			$idcontract = $contract->create($user);

			if ($idcontract <= 0)
			{
				$error++;
				setEventMessages('', array_merge($errors,($contract->error?array($contract->error):$contract->errors)), 'errors');
				$action = 'create2';
			}

			if (! $error)
			{
				$nb_user = 1;

				// Create contract line
				$product=new Product($db);
				$product->fetch($productidtocreate);
				if (empty($product->id))
				{
					$error++;
					setEventMessages($product->error, $product->errors, 'errors');
				}
				else
				{

					// Get data for contract line
					$date_start=dol_now();
					if ($contract->array_options['options_date_endfreeperiod']) $date_start=$contract->array_options['options_date_endfreeperiod'];

					if (empty($product->duration_value) || empty($product->duration_unit))
					{
						$error++;
						setEventMessages('The product '.$product->ref.' has now default duration');
					}
					else
					{
						$i = 1;
						$now = dol_now();
						while (dol_time_plus_duree($date_start, $product->duration_value * $i, $product->duration_unit) < $now)
						{
							$i++;
						}
						$date_end=dol_time_plus_duree($date_start, $product->duration_value * $i, $product->duration_unit);
					}
				}
				//var_dump("$nb_user, $product->tva_tx, $product->localtax1_tx, $product->localtax2_tx, $productidtocreate, 0, ".dol_print_date($date_start, 'dayhourlog')." - ".dol_print_date($date_end, 'dayhourlog'));exit;

				if (! $error)
				{
					$contactlineid = $contract->addline('', 0, $nb_user, $product->tva_tx, $product->localtax1_tx, $product->localtax2_tx, $productidtocreate, 0, $date_start, $date_end, 'HT', 0);
					if ($contactlineid < 0)
					{
						$error++;
						setEventMessages($contract->error, $contract->errors, 'errors');
					}
				}

				if (! $error)
				{
					$result = $contract->activateAll($user);
					if ($result <= 0)
					{
						$error++;
						setEventMessages($contract->error, $contract->errors, 'errors');
					}
				}
			}

			// Now create invoice template
			//$idcontract

		}

		if (! $error && $thirdpartyidselected > 0 && $idcontract > 0)
		{
			$db->commit();
			if (! empty($backtopage)) $url=$backtopage;
			else $url=DOL_URL_ROOT.'/contrat/card.php?id='.$idcontract;
			Header("Location: ".$url);
			exit;
		}
		else
		{
			$db->rollback();
			unset($object);
			$object=new Societe($db);
			$action='create2';
		}
	}

	// Add action to create file, etc...
	include 'refresh_action.inc.php';
}


/*
 *	View
 */

$help_url='';
llxHeader('',$langs->trans("SellYourSaasInstance"),$help_url);

$form = new Form($db);
$form2 = new Form($db2);
$formother = new FormOther($db);
$formcompany = new FormCompany($db);

$countrynotdefined=$langs->trans("ErrorSetACountryFirst").' ('.$langs->trans("SeeAbove").')';

print '<form mode="POST" action="'.$_SERVER["PHP_SELF"].'">';

print_fiche_titre($langs->trans("NewInstance"));

print '<div class="fichecenter">';


print '<div class="underbanner clearboth"></div>';
print '<table class="border" width="100%">';

print '<tr>';
print '<td class="titlefield">'.$langs->trans("Email").'</td><td>';
print '<input type="text" name="email" value="" class="minwidth300">';
print '</td>';
print '</tr>';

print '<tr>';
print '<td>'.$langs->trans("ThirdParty").'</td><td>';
print $form->select_company($object->id, 'thirdparty_id', 's.client IN (1,3)', 1);
print '</td>';
print '</tr>';

print '<tr><td></td><td>';
print '<input type="submit" name="loadthirdparty" class="button" value="'.$langs->trans("Search").'">';
print '</td></tr>';

// If thirdparty found
if ($object->id > 0)
{
	print '<tr><td colspan="2"><hr>';
	print '<div class="titre">'.$langs->trans("ThirdPartyFound").' :</div>';
	print '<input type="hidden" name="thirdpartyidselected" value="'.$object->id.'">';
	print '</td></tr>';

	print '<tr><td class="titlefield tdtop">';
	print $langs->trans('Name').'</td><td>';
	print $object->getNomUrl(1, 'customer');
	print '</td>';
	print '</tr>';

	print '<tr><td class="titlefield tdtop">';
	print $langs->trans('Address').'</td><td>';
	print $object->getFullAddress(1, '<br>');
	print '</td>';
	print '</tr>';

	// Customer code
        if ($object->client)
        {
            print '<tr><td>';
            print $langs->trans('CustomerCode').'</td><td>';
            print $object->code_client;
            if ($object->check_codeclient() <> 0) print ' <font class="error">('.$langs->trans("WrongCustomerCode").')</font>';
            print '</td>';
            print $htmllogobar; $htmllogobar='';
            print '</tr>';
        }

        // Supplier code
        if (! empty($conf->fournisseur->enabled) && $object->fournisseur && ! empty($user->rights->fournisseur->lire))
        {
            print '<tr><td>';
            print $langs->trans('SupplierCode').'</td><td>';
            print $object->code_fournisseur;
            if ($object->check_codefournisseur() <> 0) print ' <font class="error">('.$langs->trans("WrongSupplierCode").')</font>';
            print '</td>';
            print $htmllogobar; $htmllogobar='';
            print '</tr>';
        }

        // Prof ids
        $i=1; $j=0;
        while ($i <= 6)
        {
            $idprof=$langs->transcountry('ProfId'.$i,$object->country_code);
            if ($idprof!='-')
            {
                //if (($j % 2) == 0) print '<tr>';
                print '<tr>';
            	print '<td>'.$idprof.'</td><td>';
                $key='idprof'.$i;
                print $object->$key;
                if ($object->$key)
                {
                    if ($object->id_prof_check($i,$object) > 0) print ' &nbsp; '.$object->id_prof_url($i,$object);
                    else print ' <font class="error">('.$langs->trans("ErrorWrongValue").')</font>';
                }
                print '</td>';
                //if (($j % 2) == 1) print '</tr>';
                print '</tr>';
                $j++;
            }
            $i++;
        }

    // Mode de reglement par defaut
    print '<tr><td class="nowrap">';
        print $langs->trans('PaymentMode');
        print '</td><td>';
       	$form->form_modes_reglement($_SERVER['PHP_SELF'].'?socid='.$object->id,$object->mode_reglement_id,'none');
        print "</td>";
    print '</tr>';

    print '<tr><td colspan="2"><hr>';
    print '</td></tr>';
}

// If criteria to search were provided
if (GETPOST('email') || GETPOST('thirdparty_id') > 0 || $action == 'create2')
{
	if (empty($object->id))
	{
		print '<tr><td colspan="2"><hr>';
		print '<div class="titre">'.$langs->trans("NoThirdPartyFoundForThisEmail").'.</div>';
		print '<input type="hidden" name="thirdpartyidselected" value="tocreate">';
		print '</td></tr>';

		print '<tr><td class="titlefield">';
		print $langs->trans('Name').'</td><td>';
		print '<input type="text" name="nametocreate" class="minwidth300" value="">';
		print '</td>';
		print '</tr>';

		print '<tr><td class="fieldrequired">';
		print $langs->trans('Email').'</td><td>';
		print '<input type="text" name="emailtocreate" class="minwidth300" value="'.$emailtocreate.'">';
		print '</td>';
		print '</tr>';

		// Mode de reglement par defaut
		print '<tr><td class="nowrap">';
		print $langs->trans('PaymentMode');
		print '</td><td>';
		print $form->select_types_paiements($object->mode_reglement_id,'mode_reglement_id',$filtertype,0,0,0,0,1);
		print "</td>";
		print '</tr>';

		print '<tr><td colspan="2"><hr>';
		print '</td></tr>';
	}

	if ($action == 'create2')
	{
		$contractfound='';
		if ($object->id > 0)
		{
			// Check if a contract exists
			$sql='SELECT rowid, ref FROM '.MAIN_DB_PREFIX."contrat WHERE fk_soc = '".$object->id."'";
			$resql=$db->query($sql);
			if ($resql)
			{
				if ($obj = $db->fetch_object($resql))
				{
					$contractfound=$obj->ref;
				}
			}
			else
			{
				dol_print_error($db);
			}
		}

		print '<tr><td colspan="2">';
		print '<div class="titre">'.$langs->trans("ProductsToIncludeInContract").'</div>';
		print '</td></tr>';

		if (empty($contractfound))
		{
			print '<tr><td class="fieldrequired">';
			print $langs->trans('Instance').' (ex: myinstance.on.dolicloud.com)</td><td>';
			print '<input type="text" name="instancetocreate" value="'.$instancetocreate.'" class="minwidth300">';
			print '</td>';
			print '</tr>';

			print '<tr><td class="fieldrequired">';
			print $langs->trans('Product').'</td><td>';
			$defaultproductid=$conf->global->SELLYOURSAAS_DEFAULT_PRODUCT;
			print $form->select_produits($defaultproductid, 'producttocreate');
			print '</td>';
			print '</tr>';
		}
		else
		{
			print '<tr><td colspan="2">';
			print 'A contract already exists. TODO Manage 2 contracts on same customer...';
			print '</td></tr>';
		}
	}
}

print "</table><br>";

if (GETPOST('email') || GETPOST('thirdparty_id') > 0 || $action == 'create2')
{
	if ($action == 'create2' && empty($contractfound))
	{
		print '<center>';
		print '<input type="submit" name="add" class="button" value="'.$langs->trans("AddContractInstance").'">';
		print '</center>';
	}
}

print "</div>";	//  End fiche=center

print '</form>';

llxFooter();

$db->close();