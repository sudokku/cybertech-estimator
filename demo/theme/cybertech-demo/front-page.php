<?php
/**
 * Home: hero → Alida → services band → clients → about → team → contact.
 *
 * @package Cybertech_Demo
 */

declare(strict_types=1);

get_header();

get_template_part(
	'template-parts/hero',
	null,
	[
		'id'      => 'home-demo',
		'eyebrow' => '27 years of expertise',
		'title'   => 'Navigating<br>the digital ocean<br>since 1999',
		'cta'     => true,
	]
);

get_template_part( 'template-parts/alida' );

$cybertech_demo_services = [
	'web'    => [
		'title' => 'Web solutions',
		'text'  => 'Our team creates dynamic and interactive web applications, utilizing the latest technologies and frameworks.',
	],
	'mobile' => [
		'title' => 'Mobile Applications',
		'text'  => 'We specialize in crafting innovative and user-friendly mobile applications for iOS and Android platforms.',
	],
	'design' => [
		'title' => 'UI/UX Design &ndash; Web &amp; Mobile',
		'text'  => 'Our team uses modern tools to develop user friendly and elegant designs for various types of applications.',
	],
	'ai'     => [
		'title' => 'AI Integration &amp; Automation',
		'text'  => 'We help organizations automate repetitive and manual tasks through AI-powered automation solutions.',
	],
];

$cybertech_demo_clients = [
	'Enterprise' => [ 'Accenture', 'Zara', 'Steve Madden', 'Rompetrol', 'Lidl', 'REWE Group', 'Orange', 'Decathlon', 'One United Properties', 'Cavar', 'TVR', 'Ringier', 'PGMS', 'Fire Prevention Services', 'Vigra', 'Experimentează' ],
	'Startups'   => [ 'Night Vision Guys', 'Social Bluebook', 'Aniview', '360Alumni', 'Omniperform', 'Concept5', 'Penrose', 'Aijob', 'Freerub.com', 'Bravokilo', 'Netcrumb', 'Mappers', 'PartsTree', 'AutoRoti', 'Printcard' ],
	"NGO's"      => [ 'IUCN', 'IPPF', 'FENG', 'Campion Center', 'Orions', 'Wuji', 'The Westport Library', 'FIL-IDF' ],
];
?>

<section class="section services-band band-wave" id="our-services">
	<div class="container">
		<div class="section-head section-head--center">
			<span class="section-head__eyebrow">Our services</span>
			<h2 class="section-head__title section-head__title--light">Explore our diverse service offerings</h2>
		</div>
		<div class="service-grid">
			<?php foreach ( $cybertech_demo_services as $cybertech_demo_key => $cybertech_demo_card ) : ?>
				<a class="service-card" href="<?php echo esc_url( cybertech_demo_estimate_url( $cybertech_demo_key ) ); ?>">
					<span class="service-card__icon"><?php cybertech_demo_icon( $cybertech_demo_key ); ?></span>
					<span class="service-card__body">
						<span class="service-card__title"><?php echo wp_kses( $cybertech_demo_card['title'], [] ); ?></span>
						<span class="service-card__text"><?php echo esc_html( $cybertech_demo_card['text'] ); ?></span>
					</span>
				</a>
			<?php endforeach; ?>
		</div>
	</div>
</section>

<section class="section clients" id="our-clients">
	<div class="container">
		<h2 class="clients__title">Our Clients</h2>
		<p class="clients__intro">For over 27 years, Cybertech has been at the forefront of technological advancements, forging impactful collaborations with enterprise companies, startups, and NGOs alike.</p>
		<?php foreach ( $cybertech_demo_clients as $cybertech_demo_group => $cybertech_demo_names ) : ?>
			<div class="divider"><span class="divider__label"><?php echo esc_html( $cybertech_demo_group ); ?></span></div>
			<ul class="logo-wall">
				<?php foreach ( $cybertech_demo_names as $cybertech_demo_name ) : ?>
					<li><span class="logo-badge"><?php echo esc_html( $cybertech_demo_name ); ?></span></li>
				<?php endforeach; ?>
			</ul>
		<?php endforeach; ?>
	</div>
</section>

<section class="section about" id="about-us">
	<div class="container about__grid">
		<div class="about__portrait" role="img" aria-label="<?php esc_attr_e( 'Portrait placeholder', 'cybertech-demo' ); ?>"></div>
		<div class="about__content">
			<span class="about__eyebrow">About us</span>
			<h2 class="about__title">We strive to empower businesses of all sizes to thrive in the digital era.</h2>
			<p>We see ourselves as pilots for our clients, guiding them on the vast ocean of web and mobile technologies</p>
			<ul class="counters">
				<li><strong class="counters__num" data-counter="27">27</strong><span class="counters__label">Years</span></li>
				<li><strong class="counters__num" data-counter="500" data-suffix="+">500+</strong><span class="counters__label">Projects</span></li>
				<li><strong class="counters__num" data-counter="80" data-suffix="%">80%</strong><span class="counters__label">Client Retention</span></li>
			</ul>
		</div>
	</div>
</section>

<section class="section team" id="team">
	<div class="container">
		<div class="section-head section-head--center">
			<span class="section-head__eyebrow">Meet Our Team</span>
			<h2 class="section-head__title">Navigating the digital ocean for you</h2>
		</div>
		<div class="team__photo" role="img" aria-label="<?php esc_attr_e( 'Team photo placeholder', 'cybertech-demo' ); ?>"></div>
	</div>
</section>

<?php
get_template_part( 'template-parts/contact-band' );
get_footer();
