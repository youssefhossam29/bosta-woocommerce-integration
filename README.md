# Bosta WooCommerce Status & Returns Integration

A comprehensive, production-ready integration plugin connecting **WooCommerce** with **Bosta** shipping service. Designed specifically for e-commerce operations in Egypt and the MENA region, it provides end-to-end automation for order lifecycle management, role-based status access, cash-on-delivery (COD) collection tracking, and customer return pickups.

---

## Key Features

### 1. Custom Order Statuses
Adds 8 specialized order statuses tailored for Egyptian logistics workflows:
1. **Awaiting Confirmation (`wc-awaiting-confirm`):** New orders waiting for phone or customer confirmation.
2. **Confirmed / Preparing (`wc-confirm-preparing`):** Confirmed orders currently being picked and packaged.
3. **Ready to Ship - Private Agent (`wc-ready-ship-agent`):** Prepared orders allocated to in-house delivery agents.
4. **Ready to Ship - Bosta (`wc-ready-ship-bosta`):** Prepared orders waiting for Bosta pickup.
5. **Sent to Bosta (`wc-sent-to-bosta`):** Dispatched to Bosta with tracking number assigned.
6. **Handed to Bosta (`wc-handed-to-bosta`):** Picked up by Bosta courier and in transit.
7. **Handed to Agent (`wc-handed-to-agent`):** Handed over to private delivery courier.
8. **COD Collected (`wc-cod-collected`):** Cash-on-delivery payment successfully received and reconciled.

---

### 2. Role-Based Permission Control
Granular control over which team members can change specific order statuses:
* **Customer Service:** Allowed to manage initial statuses (Awaiting Confirmation, Confirmed / Preparing, Cancelled).
* **Warehouse Admin:** Controls packaging, shipping allocation, and dispatch to couriers.
* **Packaging Team:** Manages pickup handovers and shipment tracking.
* **Shop Manager & Administrator:** Full access to all statuses and settings.

---

### 3. Real-Time Webhook Automation
Instantly synchronizes order status with Bosta delivery events via REST API webhooks:
* **State 10 (Created):** Automatically transitions order to *Sent to Bosta*.
* **States 21 - 30 (Pickup & Transit):** Updates status to *Handed to Bosta*.
* **State 45 (Delivered):** Marks order as delivered and triggers automated COD collection if applicable.
* **States 46 & 60 (Returned / Cancelled):** Handles failed deliveries and customer cancellations.

---

### 4. Cash on Delivery (COD) Management
* **Automatic Collection (via Bosta):** When a delivery webhook with State 45 is received, the plugin automatically records the collected amount, sets collector as `Bosta`, timestamps the transaction, and logs a detailed order note.
* **Manual Collection (Private Courier):** When an admin or team member manually transitions an order to *COD Collected*, the system prompts/logs the collector as `Private Agent` (`مندوب خاص`) with full audit notes.

---

### 5. Complete Bosta Returns Management
Official support for Bosta **Customer Return Pickup (Type 25)** deliveries:
* **Single Order Returns:** Dedicated Meta Box on the edit order page allows specifying the number of return items, return reasons, and pickup address details.
* **Bulk Order Returns:** Create return pickup requests for multiple orders simultaneously from the WooCommerce Orders list using bulk actions.
* **Reversed Sender/Receiver Logic:** Accurately formats the API payload where the store is the sender/requester and the customer is the pickup receiver.
* **Tracking & Delivery IDs:** Automatically records `bosta_return_delivery_id` and `bosta_return_tracking_number` to postmeta / HPOS tables.

---

### 6. Architecture & Security
* **WooCommerce HPOS Compatible:** Full compatibility with High-Performance Order Storage (custom order tables) with fallback to traditional `wp_postmeta`.
* **Security & Verification:** Secure webhook signature validation using shared secrets, WordPress nonces, capability checks, and data sanitization.
* **Admin UI Enhancements:** Custom order list column showing Bosta tracking links, detailed meta boxes, and dashboard summary widgets.

---

## System Requirements

| Requirement | Minimum Supported |
| :--- | :--- |
| **WordPress** | 5.8+ |
| **WooCommerce** | 6.0+ (HPOS supported) |
| **PHP** | 7.4, 8.0, 8.1, or 8.2 |
| **Database** | MySQL 5.6+ / MariaDB 10.0+ |
| **Bosta Account** | Active business account with API v2 access |

---

## Installation & Setup

1. Upload the `bosta-woocommerce-integration` folder to `/wp-content/plugins/`.
2. Activate the plugin via **WordPress Admin > Plugins > Installed Plugins**.
3. Navigate to **WooCommerce > Settings > Bosta Integration**.
4. Configure the required options:
   * **Bosta API Key:** Enter your live/test Bosta API token.
   * **Webhook URL:** Copy the generated endpoint (`/wp-json/bosta/v1/webhook`) into your Bosta Business Dashboard.
   * **Webhook Secret:** Set a secure shared secret for payload signature verification.
   * **Role Permissions:** Select permitted order statuses for each user role.
   * **Return Address:** Provide default store pickup/drop-off details for customer return requests.

---

## Developer Reference

### Action Hooks
```php
// Fired when COD payment is marked as collected
do_action('bosta_cod_collected', $order, $amount, $type);

// Fired when processing COD from an incoming Bosta webhook
do_action('bosta_handle_cod_collection', $order, $data);
```

### Filter Hooks
```php
// Customize mapping between Bosta webhook states and WooCommerce order statuses
apply_filters('bosta_state_mapping', $status_map);
```

### Order Metadata Keys
* `bosta_tracking_number`: Primary Bosta tracking number.
* `bosta_sync_status`: Sync state (`success` / `failed`).
* `bosta_sync_date`: Timestamp of the initial synchronization.
* `cod_amount`: Amount collected upon delivery.
* `cod_collected_by`: Collector entity (`بوسطة` or `مندوب خاص`).
* `cod_collected_date`: Timestamp of COD collection.
* `bosta_return_delivery_id`: Bosta delivery ID for customer return pickup.
* `bosta_return_tracking_number`: Tracking number for the return shipment.

---

## License

This project is licensed under the **GNU General Public License v2.0 or later**. See the [LICENSE](LICENSE) file for complete details.

