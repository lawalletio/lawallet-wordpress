#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

LIGHTNING_ADDRESS="${LIGHTNING_ADDRESS:-merchant@mock-lnurl:4000}"
NOSTR_RELAYS="${NOSTR_RELAYS:-ws://localhost:4000/nostr}"
PRICE_BUFFER_PERCENT="${PRICE_BUFFER_PERCENT:-0}"
INVOICE_EXPIRY_MINUTES="${INVOICE_EXPIRY_MINUTES:-30}"

if [[ -z "${ALLOW_INSECURE_HTTP:-}" ]]; then
	if [[ "$LIGHTNING_ADDRESS" == *"mock-lnurl"* || "$LIGHTNING_ADDRESS" == *"localhost"* || "$LIGHTNING_ADDRESS" == *"127.0.0.1"* ]]; then
		ALLOW_INSECURE_HTTP="yes"
	else
		ALLOW_INSECURE_HTTP="no"
	fi
fi

if [[ -z "${MANUAL_SATS_PER_UNIT+x}" ]]; then
	if [[ "$LIGHTNING_ADDRESS" == *"mock-lnurl"* || "$LIGHTNING_ADDRESS" == *"localhost"* || "$LIGHTNING_ADDRESS" == *"127.0.0.1"* ]]; then
		MANUAL_SATS_PER_UNIT="1000"
	else
		MANUAL_SATS_PER_UNIT=""
	fi
fi

docker compose up -d --build
docker compose restart mock-lnurl >/dev/null

echo "Waiting for WordPress..."
until docker compose run --rm wpcli wp core version >/dev/null 2>&1; do
	sleep 3
done

if ! docker compose run --rm wpcli wp core is-installed >/dev/null 2>&1; then
	docker compose run --rm wpcli wp core install \
		--url="http://localhost:8080" \
		--title="Lightning Test Shop" \
		--admin_user="admin" \
		--admin_password="password" \
		--admin_email="admin@example.com" \
		--skip-email
fi

docker compose run --rm wpcli wp core update
docker compose run --rm wpcli wp plugin install woocommerce --activate --force
docker compose run --rm wpcli wp plugin activate woocommerce-lightning-lud21

docker compose run --rm wpcli wp option update blogdescription "LaWallet - Wordpress test store"
docker compose run --rm wpcli wp option update permalink_structure "/%postname%/"
docker compose run --rm wpcli wp rewrite flush
docker compose run --rm wpcli wp option update woocommerce_store_address "1 Lightning Way"
docker compose run --rm wpcli wp option update woocommerce_store_city "Buenos Aires"
docker compose run --rm wpcli wp option update woocommerce_default_country "AR:C"
docker compose run --rm wpcli wp option update woocommerce_currency "USD"
docker compose run --rm wpcli wp option update woocommerce_coming_soon "no"
docker compose run --rm wpcli wp option update woocommerce_enable_guest_checkout "yes"
docker compose run --rm wpcli wp option update woocommerce_registration_generate_username "yes"
docker compose run --rm wpcli wp option update woocommerce_registration_generate_password "yes"

get_or_create_page() {
	local slug="$1"
	local title="$2"
	local content="$3"
	local page_id

	page_id="$(docker compose run --rm wpcli wp post list --post_type=page --name="$slug" --field=ID 2>/dev/null | head -n 1 | tr -d '\r')"
	if [[ -z "$page_id" ]]; then
		docker compose run --rm wpcli wp post create --post_type=page --post_status=publish --post_title="$title" --post_name="$slug" --post_content="$content" --porcelain
	else
		docker compose run --rm wpcli wp post update "$page_id" --post_status=publish --post_title="$title" --post_content="$content" >/dev/null
		echo "$page_id"
	fi
}

CHECKOUT_ID="$(get_or_create_page checkout Checkout '[woocommerce_checkout]')"
CART_ID="$(get_or_create_page cart Cart '[woocommerce_cart]')"
SHOP_ID="$(get_or_create_page shop Shop '')"

docker compose run --rm wpcli wp option update woocommerce_checkout_page_id "$CHECKOUT_ID"
docker compose run --rm wpcli wp option update woocommerce_cart_page_id "$CART_ID"
docker compose run --rm wpcli wp option update woocommerce_shop_page_id "$SHOP_ID"

docker compose run --rm \
	-e WCLL_LIGHTNING_ADDRESS="$LIGHTNING_ADDRESS" \
	-e WCLL_NOSTR_RELAYS="$NOSTR_RELAYS" \
	-e WCLL_MANUAL_SATS_PER_UNIT="$MANUAL_SATS_PER_UNIT" \
	-e WCLL_PRICE_BUFFER_PERCENT="$PRICE_BUFFER_PERCENT" \
	-e WCLL_INVOICE_EXPIRY_MINUTES="$INVOICE_EXPIRY_MINUTES" \
	-e WCLL_ALLOW_INSECURE_HTTP="$ALLOW_INSECURE_HTTP" \
	wpcli wp eval '
$settings = array(
	"enabled"                => "yes",
	"lightning_address"      => getenv( "WCLL_LIGHTNING_ADDRESS" ),
	"title"                  => "LaWallet Lightning",
	"description"            => "Pay instantly with a Bitcoin Lightning wallet.",
	"invoice_expiry_minutes" => getenv( "WCLL_INVOICE_EXPIRY_MINUTES" ),
	"nostr_relays"           => getenv( "WCLL_NOSTR_RELAYS" ),
	"manual_sats_per_unit"   => getenv( "WCLL_MANUAL_SATS_PER_UNIT" ),
	"price_buffer_percent"   => getenv( "WCLL_PRICE_BUFFER_PERCENT" ),
	"allow_insecure_http"    => getenv( "WCLL_ALLOW_INSECURE_HTTP" ),
);

$client = new WCLL_LNURL_Client( $settings );
$check  = $client->test_lud21( $settings["lightning_address"] );

if ( is_wp_error( $check ) ) {
	$settings["lud21_verified"] = "no";
	$settings["allows_nostr"]   = "no";
	$settings["nostr_pubkey"]   = "";
	$settings["status_message"] = $check->get_error_message();
} else {
	$pay_request = $check["pay_request"];
	$settings["lud21_verified"] = "yes";
	$settings["allows_nostr"]   = ! empty( $pay_request["allowsNostr"] ) ? "yes" : "no";
	$settings["nostr_pubkey"]   = ! empty( $pay_request["nostrPubkey"] ) ? sanitize_text_field( $pay_request["nostrPubkey"] ) : "";
	$settings["verified_at"]    = gmdate( "c" );
	$settings["status_message"] = "Lightning Address supports LUD-21 verification.";
}

update_option( "woocommerce_wcll_gateway_settings", $settings );
update_option( "lawallet_discovery_enabled", "yes" );
update_option( "lawallet_gateway_endpoint", "http://mock-lnurl:4000" );
delete_option( "lawallet_gateway_last_error" );
update_option( "lawallet_gateway_verified_at", gmdate( "c" ) );
update_option(
	"lawallet_gateway_server_settings",
	array(
		"domain"              => "mock-lnurl.local",
		"endpoint"            => "http://mock-lnurl:4000",
		"subdomain"           => "http://mock-lnurl:4000",
		"brand_theme"         => "#22c55e",
		"brand_rounding"      => "Medium",
		"community_name"      => "Mock LaWallet",
		"logotype_url"        => "https://dummyimage.com/420x120/ffffff/111827.png&text=Mock+LaWallet",
		"isotypo_url"         => "https://dummyimage.com/160x160/22c55e/ffffff.png&text=LW",
		"maintenance_enabled" => "false",
		"social_website"      => "mock-lnurl.local",
		"social_twitter"      => "MockLaWallet",
		"social_telegram"     => "mocklawallet",
		"social_discord"      => "mocklawallet",
		"social_nostr"        => "mock@mock-lnurl.local",
		"social_email"        => "mock@example.com",
	)
);
echo $settings["status_message"] . "\n";
if ( "yes" !== $settings["lud21_verified"] ) {
	exit( 1 );
}
'

docker compose run --rm wpcli wp rewrite flush

docker compose run --rm wpcli wp eval '
$existing = get_page_by_path( "lightning-test-product", OBJECT, "product" );
if ( ! $existing ) {
	$product = new WC_Product_Simple();
	$product->set_name( "Lightning Test Product" );
	$product->set_slug( "lightning-test-product" );
	$product->set_regular_price( "2.50" );
	$product->set_virtual( true );
	$product->set_catalog_visibility( "visible" );
	$product->set_status( "publish" );
	$product->save();
}
'

curl -fsS -X POST http://localhost:4000/test/reset >/dev/null

echo "Local store ready: http://localhost:8080"
echo "Admin: admin / password"
echo "Product: http://localhost:8080/product/lightning-test-product/"
echo "Lightning Address: $LIGHTNING_ADDRESS"
echo "LaWallet discovery endpoint: http://mock-lnurl:4000"
