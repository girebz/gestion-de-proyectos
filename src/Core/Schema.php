<?php
/**
 * Definición del esquema de base de datos del núcleo.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Tablas propias del plugin (prefijo gdp_) y su instalación con dbDelta.
 *
 * Convenciones:
 * - Todo registro pertenece a un proyecto (project_id), salvo los catálogos
 *   globales (project_id = 0) y los tokens del conector (por usuario).
 * - Las columnas *_data almacenan JSON; nunca se consultan por su contenido.
 * - La columna version permite detectar conflictos en importaciones y en las
 *   operaciones propuestas por el conector (control optimista).
 */
final class Schema {

	/**
	 * Nombre completo de una tabla del plugin.
	 *
	 * @param string $short Nombre corto (por ejemplo, "projects").
	 * @return string
	 */
	public static function table( string $short ): string {
		global $wpdb;

		return $wpdb->prefix . 'gdp_' . $short;
	}

	/**
	 * Sentencias CREATE TABLE del núcleo, indexadas por nombre corto.
	 *
	 * @return array<string,string>
	 */
	public static function definitions(): array {
		global $wpdb;

		$collate  = $wpdb->get_charset_collate();
		$projects = self::table( 'projects' );
		$members  = self::table( 'project_members' );
		$audit    = self::table( 'audit_log' );
		$ops      = self::table( 'operations' );
		$tokens   = self::table( 'connector_tokens' );
		$catalog  = self::table( 'catalog_items' );

		return array_merge( self::core_definitions( $collate, $projects, $members, $audit, $ops, $tokens, $catalog ), self::planning_definitions( $collate ), self::documents_definitions( $collate ), self::procurement_definitions( $collate ), self::meetings_definitions( $collate ), self::finance_definitions( $collate ) );
	}

	/**
	 * Tablas del módulo de finanzas y rendición de cuentas, y de los grupos de permisos.
	 *
	 * El convenio fija fuentes, montos y plazos; las cuotas registran cada
	 * transferencia y su aceptación en la plataforma; los ítems llevan el
	 * asignado por fuente; los pagos son la unidad de rendición (un egreso
	 * puede pagar varios documentos); las rendiciones agrupan pagos por
	 * período y fuente, y sus estados en la plataforma se declaran como
	 * eventos fechados; las reglas guardan cada valor con su fuente y
	 * vigencia, para que ninguna particularidad del fondo viva en el código.
	 *
	 * @param string $collate Cotejamiento.
	 * @return array<string,string>
	 */
	private static function finance_definitions( string $collate ): array {
		$agreements    = self::table( 'finance_agreements' );
		$installments  = self::table( 'finance_installments' );
		$items         = self::table( 'finance_items' );
		$payments      = self::table( 'finance_payments' );
		$renditions    = self::table( 'finance_renditions' );
		$events        = self::table( 'finance_events' );
		$guarantees    = self::table( 'finance_guarantees' );
		$plans         = self::table( 'finance_cash_plans' );
		$plan_rows     = self::table( 'finance_cash_plan_rows' );
		$ledger        = self::table( 'finance_ledger' );
		$rules         = self::table( 'finance_rules' );
		$modifications = self::table( 'finance_modifications' );
		$groups        = self::table( 'permission_groups' );

		return array(
			'finance_agreements'     => "CREATE TABLE {$agreements} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	project_id bigint(20) unsigned NOT NULL,
	profile varchar(64) NOT NULL DEFAULT 'frpd_coquimbo_sisrec',
	funder varchar(255) NOT NULL DEFAULT '',
	program varchar(255) NOT NULL DEFAULT '',
	agreement_date date NULL,
	approval_act varchar(100) NOT NULL DEFAULT '',
	approval_date date NULL,
	start_date date NULL,
	end_date date NULL,
	months int(10) unsigned NOT NULL DEFAULT 0,
	fund_amount decimal(18,2) NOT NULL DEFAULT 0,
	cash_amount decimal(18,2) NOT NULL DEFAULT 0,
	inkind_amount decimal(18,2) NOT NULL DEFAULT 0,
	guarantee_required tinyint(1) NOT NULL DEFAULT 0,
	platform varchar(64) NOT NULL DEFAULT 'SISREC',
	platform_code varchar(100) NOT NULL DEFAULT '',
	platform_end_date date NULL,
	platform_render_until date NULL,
	bank_account varchar(255) NOT NULL DEFAULT '',
	cost_center varchar(100) NOT NULL DEFAULT '',
	notes longtext NULL,
	version int(10) unsigned NOT NULL DEFAULT 1,
	created_by bigint(20) unsigned NOT NULL DEFAULT 0,
	created_at datetime NOT NULL,
	updated_at datetime NOT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY project_id (project_id)
) {$collate};",

			'finance_installments'   => "CREATE TABLE {$installments} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	project_id bigint(20) unsigned NOT NULL,
	number int(10) unsigned NOT NULL DEFAULT 1,
	label varchar(100) NOT NULL DEFAULT '',
	amount decimal(18,2) NOT NULL DEFAULT 0,
	share_pct decimal(6,2) NOT NULL DEFAULT 0,
	window_from date NULL,
	window_to date NULL,
	report_no int(10) unsigned NOT NULL DEFAULT 0,
	cash_amount decimal(18,2) NOT NULL DEFAULT 0,
	cash_received_at date NULL,
	cash_receipt varchar(100) NOT NULL DEFAULT '',
	requested_at date NULL,
	transferred_at date NULL,
	received_at date NULL,
	receipt_number varchar(100) NOT NULL DEFAULT '',
	receipt_sent_at date NULL,
	platform_status varchar(20) NOT NULL DEFAULT 'pendiente',
	document_id bigint(20) unsigned NOT NULL DEFAULT 0,
	notes longtext NULL,
	version int(10) unsigned NOT NULL DEFAULT 1,
	created_at datetime NOT NULL,
	updated_at datetime NOT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY project_number (project_id,number)
) {$collate};",

			'finance_items'          => "CREATE TABLE {$items} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	project_id bigint(20) unsigned NOT NULL,
	slug varchar(64) NOT NULL,
	label varchar(255) NOT NULL DEFAULT '',
	item_group varchar(32) NOT NULL DEFAULT 'programa',
	assigned_fund decimal(18,2) NOT NULL DEFAULT 0,
	assigned_cash decimal(18,2) NOT NULL DEFAULT 0,
	assigned_inkind decimal(18,2) NOT NULL DEFAULT 0,
	platform_type varchar(32) NOT NULL DEFAULT 'operacion',
	platform_subclass varchar(100) NOT NULL DEFAULT '',
	budget_line varchar(64) NOT NULL DEFAULT '',
	cap_rule varchar(64) NOT NULL DEFAULT '',
	sort_order int(11) NOT NULL DEFAULT 0,
	notes text NULL,
	version int(10) unsigned NOT NULL DEFAULT 1,
	updated_at datetime NOT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY project_slug (project_id,slug)
) {$collate};",

			'finance_payments'       => "CREATE TABLE {$payments} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	project_id bigint(20) unsigned NOT NULL,
	seq_no int(10) unsigned NOT NULL DEFAULT 0,
	code varchar(32) NOT NULL DEFAULT '',
	purchase_id bigint(20) unsigned NOT NULL DEFAULT 0,
	supplier_id bigint(20) unsigned NOT NULL DEFAULT 0,
	source varchar(20) NOT NULL DEFAULT 'fondo',
	item_slug varchar(64) NOT NULL DEFAULT '',
	description varchar(255) NOT NULL DEFAULT '',
	commitment varchar(255) NOT NULL DEFAULT '',
	executed_at date NULL,
	paid_at date NULL,
	egress_number varchar(64) NOT NULL DEFAULT '',
	egress_document_id bigint(20) unsigned NOT NULL DEFAULT 0,
	doc_type varchar(32) NOT NULL DEFAULT 'factura',
	doc_number varchar(64) NOT NULL DEFAULT '',
	doc_date date NULL,
	amount decimal(18,2) NOT NULL DEFAULT 0,
	installment_no int(10) unsigned NOT NULL DEFAULT 0,
	status varchar(20) NOT NULL DEFAULT 'comprometido',
	rendition_id bigint(20) unsigned NOT NULL DEFAULT 0,
	folio int(10) unsigned NOT NULL DEFAULT 0,
	observation text NULL,
	observed_at date NULL,
	support longtext NULL,
	notes longtext NULL,
	version int(10) unsigned NOT NULL DEFAULT 1,
	created_by bigint(20) unsigned NOT NULL DEFAULT 0,
	created_at datetime NOT NULL,
	updated_at datetime NOT NULL,
	PRIMARY KEY  (id),
	KEY project_id (project_id),
	KEY project_status (project_id,status),
	KEY project_paid (project_id,paid_at),
	KEY rendition_id (rendition_id),
	KEY purchase_id (purchase_id),
	KEY supplier_id (supplier_id)
) {$collate};",

			'finance_renditions'     => "CREATE TABLE {$renditions} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	project_id bigint(20) unsigned NOT NULL,
	source varchar(20) NOT NULL DEFAULT 'fondo',
	period char(7) NOT NULL,
	kind varchar(20) NOT NULL DEFAULT 'mensual',
	status varchar(32) NOT NULL DEFAULT 'preparada',
	internal_due date NULL,
	platform_due date NULL,
	fix_due date NULL,
	sent_internal_at date NULL,
	loaded_at date NULL,
	sent_at date NULL,
	approved_at date NULL,
	returned_at date NULL,
	report_document_id bigint(20) unsigned NOT NULL DEFAULT 0,
	letter_document_id bigint(20) unsigned NOT NULL DEFAULT 0,
	notes longtext NULL,
	version int(10) unsigned NOT NULL DEFAULT 1,
	created_by bigint(20) unsigned NOT NULL DEFAULT 0,
	created_at datetime NOT NULL,
	updated_at datetime NOT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY project_period (project_id,source,period,kind),
	KEY project_status (project_id,status)
) {$collate};",

			'finance_events'         => "CREATE TABLE {$events} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	project_id bigint(20) unsigned NOT NULL,
	entity_type varchar(32) NOT NULL,
	entity_id bigint(20) unsigned NOT NULL DEFAULT 0,
	kind varchar(16) NOT NULL DEFAULT 'estado',
	event_key varchar(64) NOT NULL,
	guide varchar(64) NOT NULL DEFAULT '',
	event_date date NOT NULL,
	user_id bigint(20) unsigned NOT NULL DEFAULT 0,
	note text NULL,
	document_id bigint(20) unsigned NOT NULL DEFAULT 0,
	created_at datetime NOT NULL,
	PRIMARY KEY  (id),
	KEY entity (entity_type,entity_id,kind),
	KEY project_date (project_id,event_date)
) {$collate};",

			'finance_guarantees'     => "CREATE TABLE {$guarantees} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	project_id bigint(20) unsigned NOT NULL,
	kind varchar(32) NOT NULL DEFAULT 'fiel_cumplimiento',
	instrument varchar(32) NOT NULL DEFAULT 'boleta_garantia',
	number varchar(100) NOT NULL DEFAULT '',
	issuer varchar(255) NOT NULL DEFAULT '',
	amount decimal(18,2) NOT NULL DEFAULT 0,
	issued_at date NULL,
	valid_until date NULL,
	installment_no int(10) unsigned NOT NULL DEFAULT 0,
	document_id bigint(20) unsigned NOT NULL DEFAULT 0,
	status varchar(20) NOT NULL DEFAULT 'vigente',
	notes text NULL,
	version int(10) unsigned NOT NULL DEFAULT 1,
	created_at datetime NOT NULL,
	updated_at datetime NOT NULL,
	PRIMARY KEY  (id),
	KEY project_id (project_id)
) {$collate};",

			'finance_cash_plans'     => "CREATE TABLE {$plans} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	project_id bigint(20) unsigned NOT NULL,
	name varchar(255) NOT NULL DEFAULT '',
	status varchar(20) NOT NULL DEFAULT 'borrador',
	submitted_at date NULL,
	notes longtext NULL,
	version int(10) unsigned NOT NULL DEFAULT 1,
	created_by bigint(20) unsigned NOT NULL DEFAULT 0,
	created_at datetime NOT NULL,
	updated_at datetime NOT NULL,
	PRIMARY KEY  (id),
	KEY project_id (project_id)
) {$collate};",

			'finance_cash_plan_rows' => "CREATE TABLE {$plan_rows} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	plan_id bigint(20) unsigned NOT NULL,
	period char(7) NOT NULL,
	transfer_planned decimal(18,2) NOT NULL DEFAULT 0,
	spend_planned decimal(18,2) NOT NULL DEFAULT 0,
	cash_planned decimal(18,2) NOT NULL DEFAULT 0,
	milestone varchar(255) NOT NULL DEFAULT '',
	PRIMARY KEY  (id),
	UNIQUE KEY plan_period (plan_id,period)
) {$collate};",

			'finance_ledger'         => "CREATE TABLE {$ledger} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	project_id bigint(20) unsigned NOT NULL,
	source varchar(20) NOT NULL DEFAULT 'fondo',
	entry_date date NOT NULL,
	reference varchar(100) NOT NULL DEFAULT '',
	description varchar(255) NOT NULL DEFAULT '',
	debit decimal(18,2) NOT NULL DEFAULT 0,
	credit decimal(18,2) NOT NULL DEFAULT 0,
	kind varchar(20) NOT NULL DEFAULT 'otro',
	payment_id bigint(20) unsigned NOT NULL DEFAULT 0,
	installment_no int(10) unsigned NOT NULL DEFAULT 0,
	status varchar(20) NOT NULL DEFAULT 'por_aclarar',
	batch varchar(64) NOT NULL DEFAULT '',
	created_at datetime NOT NULL,
	PRIMARY KEY  (id),
	KEY project_date (project_id,entry_date),
	KEY payment_id (payment_id)
) {$collate};",

			'finance_rules'          => "CREATE TABLE {$rules} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	project_id bigint(20) unsigned NOT NULL,
	rule_key varchar(64) NOT NULL,
	value varchar(255) NOT NULL DEFAULT '',
	source varchar(255) NOT NULL DEFAULT '',
	valid_from date NULL,
	valid_to date NULL,
	note text NULL,
	updated_by bigint(20) unsigned NOT NULL DEFAULT 0,
	updated_at datetime NOT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY project_rule (project_id,rule_key)
) {$collate};",

			'finance_modifications'  => "CREATE TABLE {$modifications} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	project_id bigint(20) unsigned NOT NULL,
	kind varchar(32) NOT NULL DEFAULT 'reitemizacion',
	status varchar(20) NOT NULL DEFAULT 'solicitada',
	requested_at date NULL,
	approved_at date NULL,
	act_number varchar(100) NOT NULL DEFAULT '',
	document_id bigint(20) unsigned NOT NULL DEFAULT 0,
	details longtext NULL,
	notes text NULL,
	version int(10) unsigned NOT NULL DEFAULT 1,
	created_at datetime NOT NULL,
	updated_at datetime NOT NULL,
	PRIMARY KEY  (id),
	KEY project_kind (project_id,kind)
) {$collate};",

			'permission_groups'      => "CREATE TABLE {$groups} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	project_id bigint(20) unsigned NOT NULL DEFAULT 0,
	slug varchar(64) NOT NULL,
	label varchar(100) NOT NULL,
	description text NULL,
	permissions longtext NULL,
	created_at datetime NOT NULL,
	updated_at datetime NOT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY project_slug (project_id,slug)
) {$collate};",
		);
	}

	/**
	 * Tablas del módulo de reuniones y acuerdos.
	 *
	 * @param string $collate Cotejamiento.
	 * @return array<string,string>
	 */
	private static function meetings_definitions( string $collate ): array {
		$meetings   = self::table( 'meetings' );
		$attendees  = self::table( 'meeting_attendees' );
		$agreements = self::table( 'agreements' );

		return array(
			'meetings'          => "CREATE TABLE {$meetings} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	project_id bigint(20) unsigned NOT NULL,
	seq_no int(10) unsigned NOT NULL DEFAULT 0,
	code varchar(32) NOT NULL DEFAULT '',
	title varchar(255) NOT NULL,
	kind varchar(32) NOT NULL DEFAULT 'equipo',
	status varchar(20) NOT NULL DEFAULT 'programada',
	meeting_date date NOT NULL,
	start_time time NULL,
	end_time time NULL,
	location varchar(255) NOT NULL DEFAULT '',
	agenda longtext NULL,
	summary longtext NULL,
	transcript longtext NULL,
	organizer_id bigint(20) unsigned NOT NULL DEFAULT 0,
	activity_id bigint(20) unsigned NOT NULL DEFAULT 0,
	minutes_document_id bigint(20) unsigned NOT NULL DEFAULT 0,
	notes longtext NULL,
	version int(10) unsigned NOT NULL DEFAULT 1,
	created_by bigint(20) unsigned NOT NULL DEFAULT 0,
	created_at datetime NOT NULL,
	updated_at datetime NOT NULL,
	PRIMARY KEY  (id),
	KEY project_id (project_id),
	KEY meeting_date (project_id,meeting_date),
	KEY status (project_id,status)
) {$collate};",

			'meeting_attendees' => "CREATE TABLE {$attendees} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	meeting_id bigint(20) unsigned NOT NULL,
	user_id bigint(20) unsigned NOT NULL DEFAULT 0,
	name varchar(255) NOT NULL DEFAULT '',
	organization varchar(255) NOT NULL DEFAULT '',
	email varchar(255) NOT NULL DEFAULT '',
	attended tinyint(1) NOT NULL DEFAULT 1,
	PRIMARY KEY  (id),
	KEY meeting_id (meeting_id),
	KEY user_id (user_id)
) {$collate};",

			'agreements'        => "CREATE TABLE {$agreements} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	project_id bigint(20) unsigned NOT NULL,
	meeting_id bigint(20) unsigned NOT NULL,
	seq_no int(10) unsigned NOT NULL DEFAULT 0,
	code varchar(40) NOT NULL DEFAULT '',
	description text NOT NULL,
	owner_id bigint(20) unsigned NOT NULL DEFAULT 0,
	owner_name varchar(255) NOT NULL DEFAULT '',
	due_date date NULL,
	status varchar(20) NOT NULL DEFAULT 'pendiente',
	activity_id bigint(20) unsigned NOT NULL DEFAULT 0,
	fulfilled_at date NULL,
	origin varchar(20) NOT NULL DEFAULT 'manual',
	follow_up longtext NULL,
	last_review_meeting_id bigint(20) unsigned NOT NULL DEFAULT 0,
	version int(10) unsigned NOT NULL DEFAULT 1,
	created_by bigint(20) unsigned NOT NULL DEFAULT 0,
	created_at datetime NOT NULL,
	updated_at datetime NOT NULL,
	PRIMARY KEY  (id),
	KEY meeting_id (meeting_id,seq_no),
	KEY project_status (project_id,status),
	KEY owner_id (owner_id),
	KEY due_date (due_date),
	KEY activity_id (activity_id)
) {$collate};",
		);
	}

	/**
	 * Tablas del módulo de adquisiciones y presupuesto.
	 *
	 * Los montos se guardan en la moneda de origen (CLP, UF, USD) y, además,
	 * convertidos a pesos (amount_clp) con el valor de la unidad de fomento del
	 * día que se usó, para que las sumas por partida sean estables. Comprometido
	 * y ejecutado se derivan de la etapa de cada compra, no de una tabla de
	 * movimientos.
	 *
	 * @param string $collate Cotejamiento.
	 * @return array<string,string>
	 */
	private static function procurement_definitions( string $collate ): array {
		$suppliers = self::table( 'suppliers' );
		$purchases = self::table( 'purchases' );
		$stages    = self::table( 'purchase_stages' );
		$quotes    = self::table( 'quotes' );
		$items     = self::table( 'quote_items' );
		$budget    = self::table( 'budget_lines' );
		$uf        = self::table( 'uf_rates' );

		return array(
			'suppliers'       => "CREATE TABLE {$suppliers} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	project_id bigint(20) unsigned NOT NULL DEFAULT 0,
	name varchar(255) NOT NULL,
	tax_id varchar(32) NOT NULL DEFAULT '',
	contact_name varchar(255) NOT NULL DEFAULT '',
	email varchar(255) NOT NULL DEFAULT '',
	phone varchar(64) NOT NULL DEFAULT '',
	address varchar(255) NOT NULL DEFAULT '',
	category varchar(100) NOT NULL DEFAULT '',
	notes longtext NULL,
	active tinyint(1) NOT NULL DEFAULT 1,
	created_by bigint(20) unsigned NOT NULL DEFAULT 0,
	created_at datetime NOT NULL,
	updated_at datetime NOT NULL,
	PRIMARY KEY  (id),
	KEY project_id (project_id),
	KEY name (name(100))
) {$collate};",

			'purchases'       => "CREATE TABLE {$purchases} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	project_id bigint(20) unsigned NOT NULL,
	seq_no int(10) unsigned NOT NULL DEFAULT 0,
	code varchar(32) NOT NULL DEFAULT '',
	title varchar(255) NOT NULL,
	description longtext NULL,
	budget_line varchar(64) NOT NULL DEFAULT '',
	supplier_id bigint(20) unsigned NOT NULL DEFAULT 0,
	stage varchar(40) NOT NULL DEFAULT 'solicitud_cotizacion',
	status varchar(20) NOT NULL DEFAULT 'abierta',
	owner_id bigint(20) unsigned NOT NULL DEFAULT 0,
	activity_id bigint(20) unsigned NOT NULL DEFAULT 0,
	chosen_quote_id bigint(20) unsigned NOT NULL DEFAULT 0,
	currency char(3) NOT NULL DEFAULT 'CLP',
	amount_net decimal(18,4) NULL,
	tax_rate decimal(5,2) NOT NULL DEFAULT 19.00,
	amount_total decimal(18,4) NULL,
	amount_clp decimal(18,2) NULL,
	uf_rate decimal(12,2) NULL,
	uf_date date NULL,
	approved_by bigint(20) unsigned NOT NULL DEFAULT 0,
	approved_at datetime NULL,
	order_number varchar(64) NOT NULL DEFAULT '',
	order_date date NULL,
	invoice_number varchar(64) NOT NULL DEFAULT '',
	invoice_date date NULL,
	paid_at date NULL,
	expected_at date NULL,
	received_at date NULL,
	notes longtext NULL,
	version int(10) unsigned NOT NULL DEFAULT 1,
	created_by bigint(20) unsigned NOT NULL DEFAULT 0,
	created_at datetime NOT NULL,
	updated_at datetime NOT NULL,
	PRIMARY KEY  (id),
	KEY project_id (project_id),
	KEY stage (project_id,stage),
	KEY budget_line (project_id,budget_line),
	KEY supplier_id (supplier_id),
	KEY activity_id (activity_id)
) {$collate};",

			'purchase_stages' => "CREATE TABLE {$stages} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	purchase_id bigint(20) unsigned NOT NULL,
	stage varchar(40) NOT NULL,
	stage_date date NOT NULL,
	user_id bigint(20) unsigned NOT NULL DEFAULT 0,
	document_id bigint(20) unsigned NOT NULL DEFAULT 0,
	note varchar(255) NOT NULL DEFAULT '',
	created_at datetime NOT NULL,
	PRIMARY KEY  (id),
	KEY purchase_id (purchase_id,id)
) {$collate};",

			'quotes'          => "CREATE TABLE {$quotes} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	project_id bigint(20) unsigned NOT NULL,
	purchase_id bigint(20) unsigned NOT NULL,
	supplier_id bigint(20) unsigned NOT NULL DEFAULT 0,
	quote_number varchar(64) NOT NULL DEFAULT '',
	status varchar(20) NOT NULL DEFAULT 'solicitada',
	requested_at date NULL,
	quote_date date NULL,
	valid_until date NULL,
	currency char(3) NOT NULL DEFAULT 'CLP',
	amount_net decimal(18,4) NULL,
	tax_rate decimal(5,2) NOT NULL DEFAULT 19.00,
	amount_total decimal(18,4) NULL,
	document_id bigint(20) unsigned NOT NULL DEFAULT 0,
	notes longtext NULL,
	created_by bigint(20) unsigned NOT NULL DEFAULT 0,
	created_at datetime NOT NULL,
	updated_at datetime NOT NULL,
	PRIMARY KEY  (id),
	KEY purchase_id (purchase_id),
	KEY supplier_id (supplier_id),
	KEY project_id (project_id)
) {$collate};",

			'quote_items'     => "CREATE TABLE {$items} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	quote_id bigint(20) unsigned NOT NULL,
	sort_order int(11) NOT NULL DEFAULT 0,
	description varchar(255) NOT NULL,
	quantity decimal(12,3) NOT NULL DEFAULT 1,
	unit varchar(32) NOT NULL DEFAULT '',
	unit_price decimal(18,4) NOT NULL DEFAULT 0,
	line_total decimal(18,4) NOT NULL DEFAULT 0,
	selected tinyint(1) NOT NULL DEFAULT 1,
	PRIMARY KEY  (id),
	KEY quote_id (quote_id,sort_order)
) {$collate};",

			'budget_lines'    => "CREATE TABLE {$budget} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	project_id bigint(20) unsigned NOT NULL,
	line_code varchar(64) NOT NULL,
	label varchar(255) NOT NULL DEFAULT '',
	assigned_clp decimal(18,2) NOT NULL DEFAULT 0,
	sort_order int(11) NOT NULL DEFAULT 0,
	notes varchar(255) NOT NULL DEFAULT '',
	updated_at datetime NOT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY project_line (project_id,line_code)
) {$collate};",

			'uf_rates'        => "CREATE TABLE {$uf} (
	rate_date date NOT NULL,
	value_clp decimal(12,2) NOT NULL,
	source varchar(32) NOT NULL DEFAULT 'manual',
	fetched_at datetime NOT NULL,
	PRIMARY KEY  (rate_date)
) {$collate};",
		);
	}

	/**
	 * Tablas del módulo de control documental y las relaciones genéricas.
	 *
	 * gdp_links y gdp_external_refs son tablas genéricas: relacionan cualquier
	 * par de entidades (documento, actividad, compra, reunión) y guardan las
	 * referencias en sistemas externos de cualquier entidad.
	 *
	 * @param string $collate Cotejamiento.
	 * @return array<string,string>
	 */
	private static function documents_definitions( string $collate ): array {
		$documents = self::table( 'documents' );
		$versions  = self::table( 'document_versions' );
		$links     = self::table( 'links' );
		$refs      = self::table( 'external_refs' );

		return array(
			'documents'         => "CREATE TABLE {$documents} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	project_id bigint(20) unsigned NOT NULL,
	type varchar(64) NOT NULL DEFAULT 'otro',
	direction varchar(8) NOT NULL DEFAULT 'out',
	seq_no int(10) unsigned NOT NULL DEFAULT 0,
	doc_number varchar(64) NOT NULL DEFAULT '',
	doc_date date NULL,
	sender varchar(255) NOT NULL DEFAULT '',
	recipient varchar(255) NOT NULL DEFAULT '',
	subject varchar(255) NOT NULL,
	body longtext NULL,
	status varchar(20) NOT NULL DEFAULT 'borrador',
	response_due date NULL,
	responded_at date NULL,
	owner_id bigint(20) unsigned NOT NULL DEFAULT 0,
	activity_id bigint(20) unsigned NOT NULL DEFAULT 0,
	notes longtext NULL,
	current_version int(10) unsigned NOT NULL DEFAULT 0,
	version int(10) unsigned NOT NULL DEFAULT 1,
	created_by bigint(20) unsigned NOT NULL DEFAULT 0,
	created_at datetime NOT NULL,
	updated_at datetime NOT NULL,
	PRIMARY KEY  (id),
	KEY project_id (project_id),
	KEY type_seq (project_id,type,seq_no),
	KEY status (project_id,status),
	KEY response_due (response_due),
	KEY activity_id (activity_id)
) {$collate};",

			'document_versions' => "CREATE TABLE {$versions} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	document_id bigint(20) unsigned NOT NULL,
	version_no int(10) unsigned NOT NULL DEFAULT 1,
	filename varchar(255) NOT NULL,
	path varchar(255) NOT NULL,
	mime varchar(100) NOT NULL DEFAULT '',
	byte_size bigint(20) unsigned NOT NULL DEFAULT 0,
	sha256 char(64) NOT NULL DEFAULT '',
	note varchar(255) NOT NULL DEFAULT '',
	uploaded_by bigint(20) unsigned NOT NULL DEFAULT 0,
	created_at datetime NOT NULL,
	PRIMARY KEY  (id),
	KEY document_id (document_id,version_no)
) {$collate};",

			'links'             => "CREATE TABLE {$links} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	project_id bigint(20) unsigned NOT NULL,
	from_type varchar(32) NOT NULL,
	from_id bigint(20) unsigned NOT NULL,
	to_type varchar(32) NOT NULL,
	to_id bigint(20) unsigned NOT NULL,
	relation_type varchar(32) NOT NULL DEFAULT 'refers_to',
	note varchar(255) NOT NULL DEFAULT '',
	created_by bigint(20) unsigned NOT NULL DEFAULT 0,
	created_at datetime NOT NULL,
	PRIMARY KEY  (id),
	KEY from_entity (from_type,from_id),
	KEY to_entity (to_type,to_id),
	KEY project_id (project_id)
) {$collate};",

			'external_refs'     => "CREATE TABLE {$refs} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	project_id bigint(20) unsigned NOT NULL,
	entity_type varchar(32) NOT NULL,
	entity_id bigint(20) unsigned NOT NULL,
	system_name varchar(100) NOT NULL,
	ref_number varchar(100) NOT NULL DEFAULT '',
	ref_status varchar(100) NOT NULL DEFAULT '',
	url varchar(500) NOT NULL DEFAULT '',
	updated_by bigint(20) unsigned NOT NULL DEFAULT 0,
	updated_at datetime NOT NULL,
	PRIMARY KEY  (id),
	KEY entity (entity_type,entity_id),
	KEY project_id (project_id)
) {$collate};",
		);
	}

	/**
	 * Tablas del módulo de planificación y tiempo.
	 *
	 * Las fechas programadas (start_date, end_date, late_*, holguras, ruta
	 * crítica) son una caché del motor de programación: se recalculan tras cada
	 * cambio y nunca se editan a mano. Las fechas reales y las restricciones
	 * son datos de entrada.
	 *
	 * @param string $collate Cotejamiento.
	 * @return array<string,string>
	 */
	private static function planning_definitions( string $collate ): array {
		$activities   = self::table( 'activities' );
		$dependencies = self::table( 'dependencies' );
		$calendars    = self::table( 'calendars' );
		$exceptions   = self::table( 'calendar_exceptions' );
		$baselines    = self::table( 'baselines' );
		$baseline_act = self::table( 'baseline_activities' );
		$assignments  = self::table( 'assignments' );
		$progress     = self::table( 'progress' );
		$snapshots    = self::table( 'schedule_snapshots' );

		return array(
			'activities'          => "CREATE TABLE {$activities} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	project_id bigint(20) unsigned NOT NULL,
	parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
	code varchar(32) NOT NULL DEFAULT '',
	name varchar(255) NOT NULL,
	description longtext NULL,
	kind varchar(16) NOT NULL DEFAULT 'activity',
	work_front varchar(64) NOT NULL DEFAULT '',
	status varchar(20) NOT NULL DEFAULT 'pendiente',
	priority tinyint(3) unsigned NOT NULL DEFAULT 2,
	sort_order int(11) NOT NULL DEFAULT 0,
	duration int(10) unsigned NOT NULL DEFAULT 1,
	constraint_type varchar(8) NOT NULL DEFAULT 'asap',
	constraint_date date NULL,
	actual_start date NULL,
	actual_finish date NULL,
	percent tinyint(3) unsigned NOT NULL DEFAULT 0,
	owner_id bigint(20) unsigned NOT NULL DEFAULT 0,
	deliverable varchar(255) NOT NULL DEFAULT '',
	budget_line varchar(64) NOT NULL DEFAULT '',
	cost_planned decimal(18,2) NULL,
	notes longtext NULL,
	start_date date NULL,
	end_date date NULL,
	late_start date NULL,
	late_finish date NULL,
	total_float int(11) NULL,
	free_float int(11) NULL,
	is_critical tinyint(1) NOT NULL DEFAULT 0,
	schedule_conflicts text NULL,
	version int(10) unsigned NOT NULL DEFAULT 1,
	created_by bigint(20) unsigned NOT NULL DEFAULT 0,
	created_at datetime NOT NULL,
	updated_at datetime NOT NULL,
	PRIMARY KEY  (id),
	KEY project_id (project_id),
	KEY parent (project_id,parent_id,sort_order),
	KEY status (project_id,status),
	KEY owner_id (owner_id),
	KEY end_date (end_date)
) {$collate};",

			'dependencies'        => "CREATE TABLE {$dependencies} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	project_id bigint(20) unsigned NOT NULL,
	predecessor_type varchar(32) NOT NULL DEFAULT 'activity',
	predecessor_id bigint(20) unsigned NOT NULL,
	successor_id bigint(20) unsigned NOT NULL,
	type char(2) NOT NULL DEFAULT 'FS',
	lag_days int(11) NOT NULL DEFAULT 0,
	created_at datetime NOT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY pair (project_id,predecessor_type,predecessor_id,successor_id),
	KEY successor_id (successor_id)
) {$collate};",

			'calendars'           => "CREATE TABLE {$calendars} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	project_id bigint(20) unsigned NOT NULL DEFAULT 0,
	name varchar(100) NOT NULL,
	weekdays varchar(20) NOT NULL DEFAULT '1,2,3,4,5',
	is_default tinyint(1) NOT NULL DEFAULT 0,
	created_at datetime NOT NULL,
	updated_at datetime NOT NULL,
	PRIMARY KEY  (id),
	KEY project_id (project_id)
) {$collate};",

			'calendar_exceptions' => "CREATE TABLE {$exceptions} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	calendar_id bigint(20) unsigned NOT NULL,
	exception_date date NOT NULL,
	working tinyint(1) NOT NULL DEFAULT 0,
	label varchar(255) NOT NULL DEFAULT '',
	PRIMARY KEY  (id),
	UNIQUE KEY calendar_date (calendar_id,exception_date)
) {$collate};",

			'baselines'           => "CREATE TABLE {$baselines} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	project_id bigint(20) unsigned NOT NULL,
	name varchar(100) NOT NULL,
	description text NULL,
	is_current tinyint(1) NOT NULL DEFAULT 0,
	created_by bigint(20) unsigned NOT NULL DEFAULT 0,
	created_at datetime NOT NULL,
	PRIMARY KEY  (id),
	KEY project_id (project_id)
) {$collate};",

			'baseline_activities' => "CREATE TABLE {$baseline_act} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	baseline_id bigint(20) unsigned NOT NULL,
	activity_id bigint(20) unsigned NOT NULL,
	code varchar(32) NOT NULL DEFAULT '',
	name varchar(255) NOT NULL DEFAULT '',
	kind varchar(16) NOT NULL DEFAULT 'activity',
	start_date date NULL,
	end_date date NULL,
	duration int(10) unsigned NOT NULL DEFAULT 0,
	percent tinyint(3) unsigned NOT NULL DEFAULT 0,
	cost_planned decimal(18,2) NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY baseline_activity (baseline_id,activity_id)
) {$collate};",

			'assignments'         => "CREATE TABLE {$assignments} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	project_id bigint(20) unsigned NOT NULL,
	activity_id bigint(20) unsigned NOT NULL,
	user_id bigint(20) unsigned NOT NULL,
	role varchar(32) NOT NULL DEFAULT 'participante',
	allocation tinyint(3) unsigned NOT NULL DEFAULT 100,
	created_at datetime NOT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY activity_user (activity_id,user_id),
	KEY project_user (project_id,user_id)
) {$collate};",

			'progress'            => "CREATE TABLE {$progress} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	project_id bigint(20) unsigned NOT NULL,
	activity_id bigint(20) unsigned NOT NULL,
	user_id bigint(20) unsigned NOT NULL DEFAULT 0,
	reported_at datetime NOT NULL,
	percent tinyint(3) unsigned NOT NULL DEFAULT 0,
	previous_percent tinyint(3) unsigned NOT NULL DEFAULT 0,
	status varchar(20) NOT NULL DEFAULT '',
	actual_start date NULL,
	actual_finish date NULL,
	note text NULL,
	source varchar(20) NOT NULL DEFAULT 'admin',
	PRIMARY KEY  (id),
	KEY activity_id (activity_id),
	KEY project_reported (project_id,reported_at)
) {$collate};",

			'schedule_snapshots'  => "CREATE TABLE {$snapshots} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	project_id bigint(20) unsigned NOT NULL,
	week_start date NOT NULL,
	taken_at datetime NOT NULL,
	stats longtext NULL,
	data longtext NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY project_week (project_id,week_start)
) {$collate};",
		);
	}

	/**
	 * Tablas del núcleo.
	 *
	 * @param string $collate  Cotejamiento.
	 * @param string $projects Tabla de proyectos.
	 * @param string $members  Tabla de miembros.
	 * @param string $audit    Tabla de bitácora.
	 * @param string $ops      Tabla de operaciones.
	 * @param string $tokens   Tabla de tokens.
	 * @param string $catalog  Tabla de catálogos.
	 * @return array<string,string>
	 */
	private static function core_definitions( string $collate, string $projects, string $members, string $audit, string $ops, string $tokens, string $catalog ): array {
		return array(
			'projects'         => "CREATE TABLE {$projects} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	code varchar(64) NOT NULL,
	name varchar(255) NOT NULL,
	short_name varchar(100) NOT NULL DEFAULT '',
	description longtext NULL,
	funder varchar(255) NOT NULL DEFAULT '',
	funding_code varchar(100) NOT NULL DEFAULT '',
	executing_entity varchar(255) NOT NULL DEFAULT '',
	status varchar(32) NOT NULL DEFAULT 'planificacion',
	start_date date NULL,
	end_date date NULL,
	budget_total decimal(18,2) NULL,
	currency char(3) NOT NULL DEFAULT 'CLP',
	settings longtext NULL,
	version int(10) unsigned NOT NULL DEFAULT 1,
	created_by bigint(20) unsigned NULL,
	created_at datetime NOT NULL,
	updated_at datetime NOT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY code (code),
	KEY status (status)
) {$collate};",

			'project_members'  => "CREATE TABLE {$members} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	project_id bigint(20) unsigned NOT NULL,
	user_id bigint(20) unsigned NOT NULL,
	role varchar(32) NOT NULL DEFAULT 'observador',
	active tinyint(1) NOT NULL DEFAULT 1,
	created_at datetime NOT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY project_user (project_id,user_id),
	KEY user_id (user_id)
) {$collate};",

			'audit_log'        => "CREATE TABLE {$audit} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	project_id bigint(20) unsigned NOT NULL DEFAULT 0,
	user_id bigint(20) unsigned NOT NULL DEFAULT 0,
	channel varchar(20) NOT NULL DEFAULT 'admin',
	entity_type varchar(64) NOT NULL,
	entity_id bigint(20) unsigned NOT NULL DEFAULT 0,
	action varchar(32) NOT NULL,
	summary varchar(255) NOT NULL DEFAULT '',
	before_data longtext NULL,
	after_data longtext NULL,
	operation_id bigint(20) unsigned NOT NULL DEFAULT 0,
	ip varchar(45) NOT NULL DEFAULT '',
	created_at datetime NOT NULL,
	PRIMARY KEY  (id),
	KEY project_id (project_id),
	KEY entity (entity_type,entity_id),
	KEY user_id (user_id),
	KEY created_at (created_at)
) {$collate};",

			'operations'       => "CREATE TABLE {$ops} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	project_id bigint(20) unsigned NOT NULL DEFAULT 0,
	user_id bigint(20) unsigned NOT NULL DEFAULT 0,
	channel varchar(20) NOT NULL DEFAULT 'connector',
	handler varchar(64) NOT NULL,
	action varchar(32) NOT NULL,
	status varchar(20) NOT NULL DEFAULT 'proposed',
	summary varchar(255) NOT NULL DEFAULT '',
	payload longtext NULL,
	preview longtext NULL,
	before_data longtext NULL,
	result longtext NULL,
	error text NULL,
	created_at datetime NOT NULL,
	expires_at datetime NULL,
	applied_at datetime NULL,
	reverted_at datetime NULL,
	PRIMARY KEY  (id),
	KEY status (status),
	KEY project_id (project_id),
	KEY user_id (user_id)
) {$collate};",

			'connector_tokens' => "CREATE TABLE {$tokens} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	user_id bigint(20) unsigned NOT NULL,
	label varchar(100) NOT NULL DEFAULT '',
	token_hash char(64) NOT NULL,
	token_prefix char(8) NOT NULL DEFAULT '',
	scopes varchar(255) NOT NULL DEFAULT 'read',
	last_used_at datetime NULL,
	last_ip varchar(45) NOT NULL DEFAULT '',
	expires_at datetime NULL,
	revoked_at datetime NULL,
	created_at datetime NOT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY token_hash (token_hash),
	KEY user_id (user_id)
) {$collate};",

			'catalog_items'    => "CREATE TABLE {$catalog} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	project_id bigint(20) unsigned NOT NULL DEFAULT 0,
	catalog varchar(64) NOT NULL,
	slug varchar(100) NOT NULL,
	label varchar(255) NOT NULL,
	description text NULL,
	sort_order int(11) NOT NULL DEFAULT 0,
	meta longtext NULL,
	active tinyint(1) NOT NULL DEFAULT 1,
	created_at datetime NOT NULL,
	updated_at datetime NOT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY catalog_slug (project_id,catalog,slug),
	KEY catalog (catalog)
) {$collate};",
		);
	}

	/**
	 * Crea o actualiza las tablas con dbDelta.
	 *
	 * @return void
	 */
	public static function install(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( self::definitions() as $sql ) {
			dbDelta( $sql );
		}
	}

	/**
	 * Elimina todas las tablas del plugin (solo en desinstalación con borrado de datos).
	 *
	 * @return void
	 */
	public static function drop(): void {
		global $wpdb;

		foreach ( array_keys( self::definitions() ) as $short ) {
			$table = self::table( $short );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		}
	}

	/**
	 * Indica si las tablas del núcleo existen.
	 *
	 * @return bool
	 */
	public static function exists(): bool {
		global $wpdb;

		$table = self::table( 'projects' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		return $found === $table;
	}
}
