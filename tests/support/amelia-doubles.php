<?php
/**
 * A stand-in for the bridge into Amelia's application layer (issue #499),
 * for tests that run without Amelia installed. It extends the real
 * Amelia_Api, so the pack under test is the shipped one; only request() is
 * replaced, by direct writes to the fixture tables
 * (tests/support/amelia-tables.php) shaped the way Amelia Lite 2.4.11 writes
 * them: appointments in UTC, a booking and an on-site payment per customer,
 * an appointment's end set back to its start once nobody holds it, and its
 * email notifications passed through the amelia_manipulate_email_data
 * filter, honouring skipSending. A taken slot or a time outside the
 * employee's working week is refused with Amelia's own message. Every call
 * is recorded in $calls and every message that was not skipped in
 * $delivered.
 *
 * The real controllers run in the live Amelia group (AmeliaLiveTest).
 */

namespace WPMCP\Tests\Support;

use WPMCP\Integrations\Amelia_Api;
use WPMCP\Integrations\Operation_Error;

if ( ! class_exists( Fake_Amelia_Api::class ) ) {
	class Fake_Amelia_Api extends Amelia_Api {
		/** @var array<int, array{0: string, 1: array, 2: array}> */
		public static array $calls = [];

		/** @var array<int, array{to: string, subject: string}> */
		public static array $delivered = [];

		public static string $edition = 'Lite';

		public function active(): bool {
			return true;
		}

		public function edition(): string {
			return self::$edition;
		}

		private static function t( string $name ): string {
			global $wpdb;
			return $wpdb->prefix . 'amelia_' . $name;
		}

		private static function row( string $table, int $id ): ?array {
			global $wpdb;
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::t( $table ), $id ), ARRAY_A );
			return is_array( $row ) ? $row : null;
		}

		private static function unavailable(): void {
			throw new Operation_Error( 'slot_unavailable', 'Amelia refused: Time slot is unavailable', [ 'amelia' => [ 'timeSlotUnavailable' => true ] ] );
		}

		private static function utc( string $local ): \DateTimeImmutable {
			$time = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i', substr( $local, 0, 16 ), wp_timezone() );
			return $time->setTimezone( new \DateTimeZone( 'UTC' ) );
		}

		/** Refuses a start outside the employee's working week or over another appointment of theirs. */
		private static function check_slot( int $provider, \DateTimeImmutable $start, int $seconds, int $except = 0 ): void {
			global $wpdb;
			$end   = $start->modify( '+' . $seconds . ' seconds' );
			$local = $start->setTimezone( wp_timezone() );
			$days  = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE userId = %d', self::t( 'providers_to_weekdays' ), $provider ), ARRAY_A );
			if ( [] !== $days ) {
				$day = null;
				foreach ( $days as $candidate ) {
					if ( (int) $candidate['dayIndex'] === (int) $local->format( 'N' ) ) {
						$day = $candidate;
					}
				}
				$from = $local->format( 'H:i:s' );
				$to   = $end->setTimezone( wp_timezone() )->format( 'H:i:s' );
				if ( null === $day || $from < $day['startTime'] || $to > $day['endTime'] || $to <= $from ) {
					self::unavailable();
				}
			}
			$clash = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE providerId = %d AND id <> %d AND status IN ('approved', 'pending') AND bookingStart < %s AND bookingEnd > %s",
				self::t( 'appointments' ),
				$provider,
				$except,
				$end->format( 'Y-m-d H:i:s' ),
				$start->format( 'Y-m-d H:i:s' )
			) );
			if ( $clash > 0 ) {
				self::unavailable();
			}
		}

		/** Amelia's email notifications for an appointment: the employee's, then each customer's. */
		private static function notify( int $appointment_id, string $status ): void {
			global $wpdb;
			$appointment = self::row( 'appointments', $appointment_id );
			$service     = self::row( 'services', (int) $appointment['serviceId'] );
			$subject     = sprintf( '%s Appointment %s', $service['name'] ?? '', ucfirst( $status ) );
			$recipients  = [ (string) ( self::row( 'users', (int) $appointment['providerId'] )['email'] ?? '' ) ];
			foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SELECT u.email FROM %i cb JOIN %i u ON u.id = cb.customerId WHERE cb.appointmentId = %d', self::t( 'customer_bookings' ), self::t( 'users' ), $appointment_id ) ) as $email ) {
				$recipients[] = (string) $email;
			}
			foreach ( $recipients as $email ) {
				if ( '' === $email ) {
					continue;
				}
				$data = apply_filters( 'amelia_manipulate_email_data', [ 'email' => $email, 'subject' => $subject, 'body' => '', 'bcc' => [], 'attachments' => [] ] );
				if ( empty( $data['skipSending'] ) ) {
					self::$delivered[] = [ 'to' => $email, 'subject' => $subject ];
				}
			}
		}

		public function request( string $controller, array $params, array $args = [], string $method = 'POST' ): array {
			global $wpdb;
			self::$calls[] = [ $controller, $params, $args ];
			$id            = (int) ( $args['id'] ?? 0 );

			switch ( $controller ) {
				case 'Bookable\\Category\\AddCategoryController':
					$wpdb->insert( self::t( 'categories' ), array_intersect_key( $params, array_flip( [ 'name', 'status', 'position', 'color' ] ) ) );
					return [ 'category' => self::row( 'categories', (int) $wpdb->insert_id ) ];

				case 'Bookable\\Category\\UpdateCategoryController':
					$wpdb->update( self::t( 'categories' ), array_intersect_key( $params, array_flip( [ 'name', 'status', 'position', 'color' ] ) ), [ 'id' => $id ] );
					return [ 'category' => self::row( 'categories', $id ) ];

				case 'Bookable\\Service\\GetServiceController':
					$service              = self::row( 'services', $id );
					$service['providers'] = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT userId FROM %i WHERE serviceId = %d', self::t( 'providers_to_services' ), $id ) ) );
					return [ 'service' => $service ];

				case 'Bookable\\Service\\AddServiceController':
				case 'Bookable\\Service\\UpdateServiceController':
					$columns = [ 'name', 'description', 'color', 'price', 'status', 'categoryId', 'minCapacity', 'maxCapacity', 'duration', 'timeBefore', 'timeAfter', 'show' ];
					$fields  = array_intersect_key( $params, array_flip( $columns ) );
					if ( isset( $fields['show'] ) ) {
						$fields['show'] = (int) $fields['show'];
					}
					if ( 0 === $id ) {
						$wpdb->insert( self::t( 'services' ), $fields + [ 'priority' => 'least_expensive', 'position' => 0 ] );
						$id = (int) $wpdb->insert_id;
					} else {
						$wpdb->update( self::t( 'services' ), $fields, [ 'id' => $id ] );
					}
					$service = self::row( 'services', $id );
					// Amelia keeps each employee's own price and capacity unless
					// the change is applied to every employee.
					$links = [];
					foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE serviceId = %d', self::t( 'providers_to_services' ), $id ), ARRAY_A ) as $link ) {
						$links[ (int) $link['userId'] ] = $link;
					}
					foreach ( $links as $provider => $link ) {
						if ( ! in_array( $provider, array_map( 'intval', (array) ( $params['providers'] ?? [] ) ), true ) ) {
							$wpdb->delete( self::t( 'providers_to_services' ), [ 'id' => (int) $link['id'] ] );
						} elseif ( ! empty( $params['applyGlobally'] ) ) {
							$wpdb->update( self::t( 'providers_to_services' ), [ 'price' => $service['price'], 'minCapacity' => $service['minCapacity'], 'maxCapacity' => $service['maxCapacity'] ], [ 'id' => (int) $link['id'] ] );
						}
					}
					foreach ( (array) ( $params['providers'] ?? [] ) as $provider ) {
						if ( ! isset( $links[ (int) $provider ] ) ) {
							$wpdb->insert( self::t( 'providers_to_services' ), [ 'userId' => (int) $provider, 'serviceId' => $id, 'price' => $service['price'], 'minCapacity' => $service['minCapacity'], 'maxCapacity' => $service['maxCapacity'] ] );
						}
					}
					return [ 'service' => $service ];

				case 'User\\Customer\\AddCustomerController':
				case 'User\\Customer\\UpdateCustomerController':
					$fields = array_intersect_key( $params, array_flip( [ 'firstName', 'lastName', 'email', 'phone', 'note', 'status', 'type' ] ) );
					foreach ( [ 'email', 'phone', 'note' ] as $nullable ) {
						if ( isset( $fields[ $nullable ] ) && '' === $fields[ $nullable ] ) {
							$fields[ $nullable ] = null;
						}
					}
					if ( ! empty( $fields['email'] ) && (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE email = %s AND id <> %d', self::t( 'users' ), $fields['email'], $id ) ) > 0 ) {
						throw new Operation_Error( 'amelia_refused', 'Amelia refused: Email already exist.', [ 'amelia' => [] ] );
					}
					if ( 0 === $id ) {
						$wpdb->insert( self::t( 'users' ), $fields );
						$id = (int) $wpdb->insert_id;
					} else {
						$wpdb->update( self::t( 'users' ), $fields, [ 'id' => $id ] );
					}
					return [ 'user' => self::row( 'users', $id ) ];

				case 'Booking\\Appointment\\AddAppointmentController':
					$booking = (array) $params['bookings'][0];
					$start   = self::utc( (string) $params['bookingStart'] );
					$seconds = (int) $booking['duration'];
					self::check_slot( (int) $params['providerId'], $start, $seconds );
					$wpdb->insert( self::t( 'appointments' ), [
						'status'             => $booking['status'],
						'bookingStart'       => $start->format( 'Y-m-d H:i:s' ),
						'bookingEnd'         => $start->modify( '+' . $seconds . ' seconds' )->format( 'Y-m-d H:i:s' ),
						'notifyParticipants' => (int) $params['notifyParticipants'],
						'serviceId'          => (int) $params['serviceId'],
						'providerId'         => (int) $params['providerId'],
						'internalNotes'      => (string) $params['internalNotes'],
					] );
					$appointment = (int) $wpdb->insert_id;
					$wpdb->insert( self::t( 'customer_bookings' ), [ 'appointmentId' => $appointment, 'customerId' => (int) $booking['customerId'], 'status' => $booking['status'], 'price' => 50, 'persons' => (int) $booking['persons'], 'duration' => $seconds, 'customFields' => '[]', 'created' => gmdate( 'Y-m-d H:i:s' ) ] );
					$wpdb->insert( self::t( 'payments' ), [ 'customerBookingId' => (int) $wpdb->insert_id, 'amount' => 0, 'dateTime' => $start->format( 'Y-m-d H:i:s' ), 'status' => 'pending', 'gateway' => 'onSite', 'entity' => 'appointment' ] );
					self::notify( $appointment, (string) $booking['status'] );
					return [ 'appointment' => self::row( 'appointments', $appointment ) ];

				case 'Booking\\Appointment\\UpdateAppointmentTimeController':
					$current = self::row( 'appointments', $id );
					$seconds = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(duration) FROM %i WHERE appointmentId = %d', self::t( 'customer_bookings' ), $id ) );
					$start   = self::utc( (string) $params['bookingStart'] );
					self::check_slot( (int) $current['providerId'], $start, $seconds, $id );
					$wpdb->update( self::t( 'appointments' ), [ 'bookingStart' => $start->format( 'Y-m-d H:i:s' ), 'bookingEnd' => $start->modify( '+' . $seconds . ' seconds' )->format( 'Y-m-d H:i:s' ) ], [ 'id' => $id ] );
					$wpdb->update( self::t( 'payments' ), [ 'dateTime' => $start->format( 'Y-m-d H:i:s' ) ], [ 'customerBookingId' => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE appointmentId = %d', self::t( 'customer_bookings' ), $id ) ) ] );
					return [ 'appointment' => self::row( 'appointments', $id ) ];

				case 'Booking\\Appointment\\UpdateAppointmentStatusController':
					$status  = (string) $params['status'];
					$current = self::row( 'appointments', $id );
					$fields  = [ 'status' => $status ];
					if ( in_array( $status, [ 'canceled', 'rejected' ], true ) ) {
						$fields['bookingEnd'] = $current['bookingStart'];
					} else {
						$seconds = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(duration) FROM %i WHERE appointmentId = %d', self::t( 'customer_bookings' ), $id ) );
						$start   = new \DateTimeImmutable( (string) $current['bookingStart'], new \DateTimeZone( 'UTC' ) );
						self::check_slot( (int) $current['providerId'], $start, $seconds, $id );
						$fields['bookingEnd'] = $start->modify( '+' . $seconds . ' seconds' )->format( 'Y-m-d H:i:s' );
					}
					$wpdb->update( self::t( 'appointments' ), $fields, [ 'id' => $id ] );
					$wpdb->update( self::t( 'customer_bookings' ), [ 'status' => $status ], [ 'appointmentId' => $id ] );
					self::notify( $id, $status );
					return [ 'appointment' => self::row( 'appointments', $id ) ];

				case 'Booking\\Appointment\\GetTimeSlotsController':
					$service = self::row( 'services', (int) $params['serviceId'] );
					$seconds = (int) $service['duration'];
					$day     = \DateTimeImmutable::createFromFormat( '!Y-m-d', substr( (string) $params['startDateTime'], 0, 10 ), wp_timezone() );
					$slots   = [];
					for ( $i = 0; $i < 40; $i++ ) {
						$date = $day->modify( '+' . $i . ' days' );
						foreach ( (array) ( $params['providerIds'] ?? [] ) as $provider ) {
							$hours = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE userId = %d AND dayIndex = %d', self::t( 'providers_to_weekdays' ), (int) $provider, (int) $date->format( 'N' ) ), ARRAY_A );
							if ( ! is_array( $hours ) ) {
								continue;
							}
							for ( $at = $date->modify( $hours['startTime'] ); $at->modify( '+' . $seconds . ' seconds' )->format( 'H:i:s' ) <= $hours['endTime'] && $at->format( 'Y-m-d' ) === $date->format( 'Y-m-d' ); $at = $at->modify( '+60 minutes' ) ) {
								try {
									self::check_slot( (int) $provider, $at->setTimezone( new \DateTimeZone( 'UTC' ) ), $seconds );
								} catch ( Operation_Error $e ) {
									continue;
								}
								$slots[ $date->format( 'Y-m-d' ) ][ $at->format( 'H:i' ) ][] = [ (int) $provider, null ];
							}
						}
					}
					return [ 'slots' => $slots, 'duration' => (int) ( $seconds / 60 ) ];
			}
			throw new Operation_Error( 'amelia_refused', 'The Amelia stand-in has no ' . $controller . '.' );
		}
	}
}
