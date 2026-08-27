<?php
/**
 * Template Name: Services
 *
 * Reproduces cybertech.ro/services/.
 *
 * @package Cybertech_Demo
 */

declare(strict_types=1);

get_header();

get_template_part(
	'template-parts/hero',
	null,
	[
		'id'      => 'services',
		'eyebrow' => '27 years of expertise',
		'title'   => 'Our services<br>explained',
	]
);

$cybertech_demo_sections = [
	[
		'id'    => 'web-solutions',
		'title' => 'Web solutions',
		'intro' => 'We develop a wide range of web software, from simple presentation websites to complex e-commerce platforms and custom applications. Our team ensures scalable, secure, and high-performance solutions tailored to your business needs.',
		'chips' => [
			'label' => 'Main technologies:',
			'items' => [ 'PHP', 'JavaScript', 'Node.js', 'Python', 'Vue.js', 'Angular', 'React', 'WordPress', 'Drupal', 'Joomla', 'Next.js', 'Django', 'Magento', 'PrestaShop' ],
		],
		'lists' => [
			[
				'label' => 'CMS Expertise:',
				'items' => [ 'WordPress, Drupal, Django CMS, Joomla', 'E-commerce platforms: PrestaShop, Magento' ],
			],
		],
		'outro' => 'From concept to deployment and maintenance, we create intuitive, high-performance apps that engage users and enhance your mobile presence. We also offer <strong>custom CMS and web applications</strong> built to your specific requirements, providing a fully tailored solution for your business processes.',
	],
	[
		'id'    => 'mobile-applications',
		'title' => 'Mobile applications',
		'intro' => 'We specialize in <strong>hybrid mobile applications</strong> that share a single code base for both iOS and Android, ensuring faster development and consistent performance across devices.',
		'lists' => [
			[
				'label' => 'Technologies we use:',
				'items' => [ 'Flutter', 'Ionic', 'React Native' ],
			],
		],
		'outro' => 'From concept to deployment and maintenance, we create intuitive, high-performance apps that engage users and enhance your mobile presence.',
	],
	[
		'id'    => 'design',
		'title' => 'UI/UX Design &ndash; Web &amp; Mobile',
		'intro' => 'We craft <strong>engaging and intuitive interfaces</strong> for both web and mobile platforms, focusing on user experience, accessibility, and modern design trends.',
		'lists' => [
			[
				'label' => 'Our services include:',
				'items' => [ 'Wireframes, prototypes, and interactive mockups', 'User research and persona development', 'Responsive and mobile-first design', 'Visual design that aligns with your brand identity', 'Usability testing and continuous improvement' ],
			],
			[
				'label' => 'Tools we use:',
				'items' => [ 'Figma – for collaborative design and prototyping', 'Adobe Illustrator – for high-quality visual design and UI elements' ],
			],
		],
		'outro' => 'Our design philosophy ensures that every interaction feels natural, your users navigate seamlessly, and your digital products deliver maximum value.',
	],
	[
		'id'    => 'ai-integration',
		'title' => 'AI Integration &amp; Automation',
		'intro' => 'We help businesses <strong>streamline processes, automate workflows, and integrate AI-powered solutions</strong> into web and mobile applications. Our focus is on efficiency, scalability, and seamless integration with your existing systems.',
		'lists' => [
			[
				'label' => 'Our services include:',
				'items' => [ 'Workflow automation and process optimization', 'Integration with third-party APIs and services', 'Custom AI solutions tailored to your business needs' ],
			],
			[
				'label' => 'Tools we use:',
				'items' => [ '<strong>n8n</strong> – for advanced workflow automation', '<strong>Vapi</strong> – for AI API integrations and intelligent automation', '<strong>Gemini and OpenAI</strong> – for integrating advanced Large Language Models (LLMs) to power intelligent decision-making within workflows.' ],
			],
		],
		'outro' => 'Our approach ensures that your business leverages the latest AI technologies to save time, reduce errors, and enhance decision-making.',
	],
];

$cybertech_demo_inline = [ 'strong' => [], 'em' => [], 'b' => [] ];

foreach ( $cybertech_demo_sections as $cybertech_demo_s ) :
	?>
	<section class="section service-section" id="<?php echo esc_attr( $cybertech_demo_s['id'] ); ?>">
		<div class="container container--narrow">
			<span class="section-head__eyebrow">Services</span>
			<h2 class="section-head__title"><?php echo wp_kses( $cybertech_demo_s['title'], [] ); ?></h2>
			<p><?php echo wp_kses( $cybertech_demo_s['intro'], $cybertech_demo_inline ); ?></p>

			<?php if ( isset( $cybertech_demo_s['chips'] ) ) : ?>
				<h5><?php echo esc_html( $cybertech_demo_s['chips']['label'] ); ?></h5>
				<ul class="chip-list">
					<?php foreach ( $cybertech_demo_s['chips']['items'] as $cybertech_demo_chip ) : ?>
						<li><?php echo esc_html( $cybertech_demo_chip ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<?php foreach ( $cybertech_demo_s['lists'] as $cybertech_demo_list ) : ?>
				<h5><?php echo esc_html( $cybertech_demo_list['label'] ); ?></h5>
				<ul class="dot-list">
					<?php foreach ( $cybertech_demo_list['items'] as $cybertech_demo_item ) : ?>
						<li><?php echo wp_kses( $cybertech_demo_item, $cybertech_demo_inline ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endforeach; ?>

			<p><?php echo wp_kses( $cybertech_demo_s['outro'], $cybertech_demo_inline ); ?></p>
		</div>
	</section>
	<?php
endforeach;

get_template_part( 'template-parts/alida' );
get_template_part( 'template-parts/contact-band' );
get_footer();
