<?php
/**
 * Public estimate page body. Standalone document; everything comes from the
 * lead snapshot via SharePage::build_data(). No email/phone of the visitor
 * ever appears here; in band mode no figure does either.
 *
 * @package Cybertech\Estimator
 * @var array<string, mixed> $data See SharePage::build_data().
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ct_est_brand     = (array) $data['brand'];
$ct_est_narrative = (array) $data['narrative'];
$ct_est_team      = (array) $data['team'];
$ct_est_labels    = (array) $data['labels'];
$ct_est_phases    = (array) $ct_est_narrative['phases'];
$ct_est_headline  = '' !== (string) $ct_est_narrative['headline']
	? (string) $ct_est_narrative['headline']
	: sprintf(
		/* translators: %s: service line label */
		__( 'Your %s estimate', 'cybertech-estimator' ),
		(string) $data['service_label']
	);
$ct_est_contact_url = (string) $data['contact_url'];
$ct_est_email       = (string) $ct_est_brand['contact_email'];
$ct_est_phone       = (string) $ct_est_brand['contact_phone'];
$ct_est_tel         = preg_replace( '/[^0-9+]/', '', $ct_est_phone );
?>
<a class="ct-share__skip" href="#ct-share-main"><?php esc_html_e( 'Skip to content', 'cybertech-estimator' ); ?></a>

<header class="ct-share__hero">
	<div class="ct-share__container">
		<p class="ct-share__logo">
			<?php if ( '' !== (string) $ct_est_brand['logo'] ) : ?>
				<img src="<?php echo esc_url( (string) $ct_est_brand['logo'] ); ?>" alt="<?php echo esc_attr( (string) $ct_est_brand['logo_alt'] ); ?>" width="236" height="39" decoding="async">
			<?php else : ?>
				<span class="ct-share__logo-text"><?php echo esc_html( (string) $ct_est_brand['company'] ); ?></span>
			<?php endif; ?>
		</p>
		<p class="ct-share__eyebrow"><?php esc_html_e( 'Project estimate', 'cybertech-estimator' ); ?></p>
		<h1 class="ct-share__headline"><?php echo esc_html( $ct_est_headline ); ?></h1>
		<p class="ct-share__prepared">
			<?php if ( '' !== (string) $data['prepared_for'] ) : ?>
				<span>
				<?php
				printf(
					/* translators: %s: recipient (first name and/or company) */
					esc_html__( 'Prepared for %s', 'cybertech-estimator' ),
					'<strong>' . esc_html( (string) $data['prepared_for'] ) . '</strong>'
				);
				?>
				</span>
			<?php endif; ?>
			<?php if ( '' !== (string) $data['created'] ) : ?>
				<span class="ct-share__sep" aria-hidden="true">·</span>
				<time><?php echo esc_html( (string) $data['created'] ); ?></time>
			<?php endif; ?>
		</p>
	</div>
</header>

<main class="ct-share__main" id="ct-share-main">
	<div class="ct-share__container">

		<section class="ct-share__summary" aria-labelledby="ct-share-figures-title">
			<h2 class="ct-share__sr-only" id="ct-share-figures-title"><?php esc_html_e( 'Key figures', 'cybertech-estimator' ); ?></h2>
			<p class="ct-share__service">
				<span class="ct-share__chip"><?php echo esc_html( (string) $data['service_label'] ); ?></span>
				<span class="ct-share__ref">
				<?php
				printf(
					/* translators: %d: lead id */
					esc_html__( 'Ref #%d', 'cybertech-estimator' ),
					(int) $data['lead_id']
				);
				?>
				</span>
			</p>
			<dl class="ct-share__figures">
				<div class="ct-share__figure ct-share__figure--main">
					<?php if ( $data['show_figures'] ) : ?>
						<dt><?php esc_html_e( 'Indicative range', 'cybertech-estimator' ); ?></dt>
						<dd class="ct-share__figure-value ct-share__figure-value--range"><?php echo esc_html( (string) $data['range_text'] ); ?></dd>
					<?php else : ?>
						<dt><?php esc_html_e( 'Budget band', 'cybertech-estimator' ); ?></dt>
						<dd class="ct-share__figure-value ct-share__figure-value--band"><?php echo esc_html( (string) $data['band_label'] ); ?></dd>
					<?php endif; ?>
				</div>
				<div class="ct-share__figure">
					<dt><?php esc_html_e( 'Timeline', 'cybertech-estimator' ); ?></dt>
					<dd class="ct-share__figure-value">
						<span class="ct-share__num"><?php echo esc_html( number_format_i18n( (int) $data['weeks'] ) ); ?></span>
						<span class="ct-share__unit"><?php echo esc_html( _n( 'week', 'weeks', (int) $data['weeks'], 'cybertech-estimator' ) ); ?></span>
					</dd>
				</div>
				<div class="ct-share__figure">
					<dt><?php esc_html_e( 'Team', 'cybertech-estimator' ); ?></dt>
					<dd class="ct-share__figure-value">
						<span class="ct-share__num"><?php echo esc_html( number_format_i18n( (int) $data['team_size'] ) ); ?></span>
						<span class="ct-share__unit"><?php echo esc_html( _n( 'role', 'roles', (int) $data['team_size'], 'cybertech-estimator' ) ); ?></span>
					</dd>
				</div>
			</dl>
			<hr class="ct-share__horizon" aria-hidden="true">
			<div class="ct-share__actions">
				<button type="button" class="ct-share__btn ct-share__btn--small" data-ct-copy data-url="<?php echo esc_url( (string) $data['share_url'] ); ?>" data-copied="<?php esc_attr_e( 'Link copied', 'cybertech-estimator' ); ?>" data-failed="<?php esc_attr_e( 'Copy failed — use the address bar', 'cybertech-estimator' ); ?>" hidden>
					<?php esc_html_e( 'Copy link', 'cybertech-estimator' ); ?>
				</button>
				<button type="button" class="ct-share__btn ct-share__btn--small" data-ct-print hidden>
					<?php esc_html_e( 'Print / Save as PDF', 'cybertech-estimator' ); ?>
				</button>
				<p class="ct-share__status" role="status" aria-live="polite" data-ct-status></p>
			</div>
		</section>

		<?php if ( '' !== (string) $ct_est_narrative['summary'] ) : ?>
		<section class="ct-share__section" aria-labelledby="ct-share-summary-title">
			<h2 class="ct-share__h2" id="ct-share-summary-title"><?php esc_html_e( 'Summary', 'cybertech-estimator' ); ?></h2>
			<p class="ct-share__lede"><?php echo esc_html( (string) $ct_est_narrative['summary'] ); ?></p>
		</section>
		<?php endif; ?>

		<?php if ( $ct_est_phases ) : ?>
		<section class="ct-share__section" aria-labelledby="ct-share-timeline-title">
			<h2 class="ct-share__h2" id="ct-share-timeline-title"><?php esc_html_e( 'Timeline', 'cybertech-estimator' ); ?></h2>
			<ol class="ct-share__phases" style="--ct-phases: <?php echo (int) count( $ct_est_phases ); ?>">
				<?php foreach ( $ct_est_phases as $ct_est_i => $ct_est_phase ) : ?>
					<li class="ct-share__phase">
						<span class="ct-share__phase-dot" aria-hidden="true"><?php echo (int) $ct_est_i + 1; ?></span>
						<div class="ct-share__phase-body">
							<p class="ct-share__phase-weeks">
								<?php
								printf(
									/* translators: %d: number of weeks */
									esc_html( _n( '%d week', '%d weeks', (int) $ct_est_phase['weeks'], 'cybertech-estimator' ) ),
									(int) $ct_est_phase['weeks']
								);
								?>
							</p>
							<h3 class="ct-share__phase-name"><?php echo esc_html( (string) $ct_est_phase['name'] ); ?></h3>
							<?php if ( '' !== (string) $ct_est_phase['description'] ) : ?>
								<p class="ct-share__phase-desc"><?php echo esc_html( (string) $ct_est_phase['description'] ); ?></p>
							<?php endif; ?>
							<?php if ( $ct_est_phase['roles'] ) : ?>
								<ul class="ct-share__chips" aria-label="<?php esc_attr_e( 'Roles involved', 'cybertech-estimator' ); ?>">
									<?php foreach ( (array) $ct_est_phase['roles'] as $ct_est_role ) : ?>
										<li class="ct-share__chip ct-share__chip--role"><?php echo esc_html( (string) $ct_est_role ); ?></li>
									<?php endforeach; ?>
								</ul>
							<?php endif; ?>
						</div>
					</li>
				<?php endforeach; ?>
			</ol>
		</section>
		<?php endif; ?>

		<?php if ( $ct_est_team ) : ?>
		<section class="ct-share__section" aria-labelledby="ct-share-team-title">
			<h2 class="ct-share__h2" id="ct-share-team-title"><?php esc_html_e( 'Team', 'cybertech-estimator' ); ?></h2>
			<p class="ct-share__lede ct-share__lede--small">
				<?php
				printf(
					/* translators: 1: total hours, 2: number of roles */
					esc_html__( 'About %1$s hours of work across %2$d roles. Hours are indicative and shift as we refine scope together.', 'cybertech-estimator' ),
					esc_html( number_format_i18n( (int) $data['hours'] ) ),
					(int) $data['team_size']
				);
				?>
			</p>
			<div class="ct-share__table-wrap">
				<table class="ct-share__table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Role', 'cybertech-estimator' ); ?></th>
							<th scope="col" class="ct-share__td-num"><?php esc_html_e( 'Hours', 'cybertech-estimator' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Share', 'cybertech-estimator' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $ct_est_team as $ct_est_row ) : ?>
							<?php $ct_est_pct = (int) round( (float) $ct_est_row['share'] * 100 ); ?>
							<tr>
								<th scope="row"><?php echo esc_html( (string) $ct_est_row['label'] ); ?></th>
								<td class="ct-share__td-num">
								<?php
								printf(
									/* translators: %s: hours */
									esc_html__( '~%s h', 'cybertech-estimator' ),
									esc_html( number_format_i18n( (int) $ct_est_row['hours'] ) )
								);
								?>
								</td>
								<td>
									<span class="ct-share__bar" style="--share: <?php echo esc_attr( (string) round( (float) $ct_est_row['share'], 4 ) ); ?>" aria-hidden="true"></span>
									<span class="ct-share__pct"><?php echo esc_html( $ct_est_pct . '%' ); ?></span>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</section>
		<?php endif; ?>

		<?php if ( $ct_est_labels ) : ?>
		<section class="ct-share__section" aria-labelledby="ct-share-answers-title">
			<h2 class="ct-share__h2" id="ct-share-answers-title"><?php esc_html_e( 'What you told us', 'cybertech-estimator' ); ?></h2>
			<dl class="ct-share__answers">
				<?php foreach ( $ct_est_labels as $ct_est_row ) : ?>
					<div class="ct-share__answer">
						<dt><?php echo esc_html( (string) $ct_est_row['label'] ); ?></dt>
						<dd><?php echo esc_html( (string) $ct_est_row['value'] ); ?></dd>
					</div>
				<?php endforeach; ?>
			</dl>
		</section>
		<?php endif; ?>

		<?php if ( $ct_est_narrative['assumptions'] || $ct_est_narrative['risks'] ) : ?>
		<section class="ct-share__section" aria-labelledby="ct-share-notes-title">
			<h2 class="ct-share__sr-only" id="ct-share-notes-title"><?php esc_html_e( 'Assumptions and risks', 'cybertech-estimator' ); ?></h2>
			<div class="ct-share__cards">
				<?php if ( $ct_est_narrative['assumptions'] ) : ?>
					<div class="ct-share__card">
						<h3 class="ct-share__h3"><?php esc_html_e( 'Assumptions', 'cybertech-estimator' ); ?></h3>
						<ul class="ct-share__list ct-share__list--assumptions">
							<?php foreach ( (array) $ct_est_narrative['assumptions'] as $ct_est_item ) : ?>
								<li><?php echo esc_html( (string) $ct_est_item ); ?></li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endif; ?>
				<?php if ( $ct_est_narrative['risks'] ) : ?>
					<div class="ct-share__card">
						<h3 class="ct-share__h3"><?php esc_html_e( 'Risks', 'cybertech-estimator' ); ?></h3>
						<ul class="ct-share__list ct-share__list--risks">
							<?php foreach ( (array) $ct_est_narrative['risks'] as $ct_est_item ) : ?>
								<li><?php echo esc_html( (string) $ct_est_item ); ?></li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endif; ?>
			</div>
		</section>
		<?php endif; ?>

		<p class="ct-share__disclaimer">
			<?php esc_html_e( 'Indicative estimate based on the answers provided; not a binding quote.', 'cybertech-estimator' ); ?>
			<?php if ( '' !== (string) $data['expires'] ) : ?>
				<?php
				printf(
					/* translators: %s: expiry date */
					esc_html__( 'Valid until %s.', 'cybertech-estimator' ),
					esc_html( (string) $data['expires'] )
				);
				?>
			<?php endif; ?>
		</p>
	</div>

	<section class="ct-share__cta" aria-labelledby="ct-share-cta-title">
		<div class="ct-share__container">
			<h2 class="ct-share__cta-title" id="ct-share-cta-title"><?php esc_html_e( 'Ready to dive in?', 'cybertech-estimator' ); ?></h2>
			<p class="ct-share__cta-sub">
				<?php
				printf(
					/* translators: %s: brand company name */
					esc_html__( 'Tell us where this lands and %s will turn it into a firm proposal.', 'cybertech-estimator' ),
					esc_html( (string) $ct_est_brand['company'] )
				);
				?>
			</p>
			<div class="ct-share__cta-actions">
				<?php if ( '' !== $ct_est_contact_url ) : ?>
					<a class="ct-share__btn ct-share__btn--light" href="<?php echo esc_url( $ct_est_contact_url ); ?>"><?php esc_html_e( 'Get in touch', 'cybertech-estimator' ); ?></a>
				<?php endif; ?>
				<?php if ( '' !== $ct_est_email ) : ?>
					<a class="ct-share__btn ct-share__btn--light" href="<?php echo esc_url( 'mailto:' . $ct_est_email ); ?>"><?php echo esc_html( $ct_est_email ); ?></a>
				<?php endif; ?>
				<?php if ( '' !== $ct_est_phone ) : ?>
					<a class="ct-share__btn ct-share__btn--light" href="<?php echo esc_url( 'tel:' . $ct_est_tel ); ?>"><?php echo esc_html( $ct_est_phone ); ?></a>
				<?php endif; ?>
			</div>
		</div>
	</section>
</main>

<footer class="ct-share__footer">
	<div class="ct-share__container">
		<p class="ct-share__legal">
			<?php echo esc_html( (string) $ct_est_brand['legal_name'] ); ?>
			<?php if ( '' !== (string) $ct_est_brand['tagline'] ) : ?>
				<span class="ct-share__sep" aria-hidden="true">·</span>
				<span><?php echo esc_html( (string) $ct_est_brand['tagline'] ); ?></span>
			<?php endif; ?>
		</p>
		<p class="ct-share__meta">
			<?php
			printf(
				/* translators: 1: lead id, 2: rate card version */
				esc_html__( 'Estimate ref #%1$d · rate card v%2$d', 'cybertech-estimator' ),
				(int) $data['lead_id'],
				(int) $data['rate_card_version']
			);
			?>
		</p>
		<p class="ct-share__url" data-url="<?php echo esc_attr( (string) $data['share_url'] ); ?>"></p>
	</div>
</footer>

<script>
(function () {
	var copy = document.querySelector('[data-ct-copy]');
	var print = document.querySelector('[data-ct-print]');
	var status = document.querySelector('[data-ct-status]');
	function say(text) { if (status) { status.textContent = text; } }
	if (print && typeof window.print === 'function') {
		print.hidden = false;
		print.addEventListener('click', function () { window.print(); });
	}
	if (copy) {
		copy.hidden = false;
		copy.addEventListener('click', function () {
			var url = copy.getAttribute('data-url') || window.location.href;
			function ok() { say(copy.getAttribute('data-copied')); }
			function fail() { say(copy.getAttribute('data-failed')); }
			if (navigator.clipboard && window.isSecureContext) {
				navigator.clipboard.writeText(url).then(ok, fail);
				return;
			}
			var ta = document.createElement('textarea');
			ta.value = url;
			ta.setAttribute('readonly', '');
			ta.style.position = 'fixed';
			ta.style.opacity = '0';
			document.body.appendChild(ta);
			ta.select();
			var done = false;
			try { done = document.execCommand('copy'); } catch (e) { done = false; }
			document.body.removeChild(ta);
			if (done) { ok(); } else { fail(); }
		});
	}
})();
</script>
