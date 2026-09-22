<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>Privacy Policy — {{ config('app.name', 'CLSU Telemedicine') }}</title>
        <meta name="description" content="Privacy Policy for the CLSU Infirmary Telemedicine System capstone testing deployment.">

        <link rel="icon" type="image/svg+xml" href="/favicon.svg">

        <!-- Fonts (matches layouts/app.blade.php and layouts/guest.blade.php) -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans antialiased bg-white text-gray-900">
        <header class="sticky top-0 z-50 border-b border-gray-200 bg-white/95 backdrop-blur">
            <div class="mx-auto flex max-w-3xl items-center justify-between gap-4 px-6 py-4 lg:px-8">
                <a href="{{ url('/') }}" class="flex items-center gap-3">
                    <x-application-logo class="w-8 h-8 fill-current text-green-700" />
                    <span class="text-sm font-semibold text-green-800">CLSU Campus Telemedicine</span>
                </a>
                <a href="{{ url('/') }}" class="text-sm font-medium text-emerald-700 hover:text-emerald-900">← Back to Home</a>
            </div>
        </header>

        <main class="mx-auto max-w-3xl px-6 py-12 lg:px-8">
            <h1 class="text-3xl font-extrabold text-emerald-800">Privacy Policy</h1>
            <p class="mt-2 text-lg font-semibold text-gray-700">CLSU Infirmary Telemedicine System</p>
            <p class="mt-1 text-sm text-gray-500">Effective Date: September 22, 2026 &nbsp;·&nbsp; Last Updated: September 22, 2026</p>

            <div class="mt-8 space-y-10 text-sm leading-relaxed text-gray-700">

                <section>
                    <h2 class="text-xl font-bold text-emerald-800">1. Introduction</h2>
                    <div class="mt-3 space-y-3">
                        <p>The CLSU Infirmary Telemedicine System is a capstone project developed by the researchers as part of their academic requirements. The system is intended to support selected telemedicine-related processes of the CLSU Infirmary, including patient registration, consultation requests, scheduling, online consultation, messaging, and related documentation.</p>
                        <p>During the current <strong>five-day live testing period</strong>, the system is being evaluated through actual use by authorized CLSU Infirmary personnel and selected users. The purpose of this testing is to evaluate the system's functionality, usability, performance, and user acceptance.</p>
                        <p>The system is <strong>currently a capstone testing deployment and is not represented as an officially integrated CLSU production system</strong>. The researchers are continuing to develop the system toward potential future integration with CLSU's existing information systems.</p>
                        <p>This Privacy Policy explains how personal information is collected, used, stored, accessed, protected, and disposed of when the system is used.</p>
                        <p>This policy is intended to be consistent with the principles and requirements of the <strong>Data Privacy Act of 2012 (Republic Act No. 10173)</strong> and its implementing rules and regulations.</p>
                    </div>
                </section>

                <section>
                    <h2 class="text-xl font-bold text-emerald-800">2. Personal Information We Collect</h2>
                    <p class="mt-3">Depending on how you use the system, we may collect the following information:</p>

                    <h3 class="mt-5 text-base font-semibold text-emerald-700">A. Account and Identification Information</h3>
                    <ul class="mt-2 list-disc space-y-1 pl-5">
                        <li>Full name</li>
                        <li>Email address</li>
                        <li>Contact information, when required</li>
                        <li>User role or account type</li>
                        <li>Account credentials and authentication-related information</li>
                    </ul>

                    <h3 class="mt-5 text-base font-semibold text-emerald-700">B. Consultation and Health-Related Information</h3>
                    <p class="mt-2">When a user submits a consultation request or participates in a telemedicine consultation, the system may process information such as:</p>
                    <ul class="mt-2 list-disc space-y-1 pl-5">
                        <li>Reason for consultation</li>
                        <li>Health-related concerns or symptoms</li>
                        <li>Medical history provided by the patient</li>
                        <li>Initial assessment information provided during the consultation process</li>
                        <li>Consultation messages</li>
                        <li>Information provided by the patient during the consultation</li>
                        <li>Prescriptions or other consultation-related documents, when applicable</li>
                        <li>Other information reasonably necessary to facilitate the requested telemedicine service</li>
                    </ul>
                    <p class="mt-2">Health information is considered <strong>sensitive personal information</strong> under the Data Privacy Act.</p>

                    <h3 class="mt-5 text-base font-semibold text-emerald-700">C. Scheduling and System Information</h3>
                    <p class="mt-2">The system may also process:</p>
                    <ul class="mt-2 list-disc space-y-1 pl-5">
                        <li>Consultation request dates and times</li>
                        <li>Scheduled consultation information</li>
                        <li>Consultation status</li>
                        <li>System-generated timestamps</li>
                        <li>Login and authentication information</li>
                        <li>Technical information necessary to maintain system security and functionality</li>
                    </ul>
                    <p class="mt-2">The system will only collect information that is necessary and relevant to its declared purposes.</p>
                </section>

                <section>
                    <h2 class="text-xl font-bold text-emerald-800">3. Purposes of Processing</h2>
                    <p class="mt-3">Personal information collected through the system may be processed for the following purposes:</p>

                    <h3 class="mt-5 text-base font-semibold text-emerald-700">A. Providing Telemedicine Services</h3>
                    <p class="mt-2">Information may be used to:</p>
                    <ul class="mt-2 list-disc space-y-1 pl-5">
                        <li>Create and maintain a user account;</li>
                        <li>Receive and manage consultation requests;</li>
                        <li>Conduct initial assessment and triage;</li>
                        <li>Assign consultation requests to authorized healthcare personnel;</li>
                        <li>Schedule consultations;</li>
                        <li>Facilitate online consultations;</li>
                        <li>Enable communication between authorized patients and healthcare personnel;</li>
                        <li>Provide consultation-related documents or prescriptions when applicable; and</li>
                        <li>Maintain records necessary for the operation of the telemedicine service.</li>
                    </ul>

                    <h3 class="mt-5 text-base font-semibold text-emerald-700">B. System Administration and Security</h3>
                    <p class="mt-2">Information may be processed to:</p>
                    <ul class="mt-2 list-disc space-y-1 pl-5">
                        <li>Authenticate users;</li>
                        <li>Control access according to user roles;</li>
                        <li>Monitor system activity;</li>
                        <li>Maintain system security;</li>
                        <li>Detect and investigate unauthorized access or suspicious activity;</li>
                        <li>Maintain system availability and reliability; and</li>
                        <li>Troubleshoot technical problems.</li>
                    </ul>

                    <h3 class="mt-5 text-base font-semibold text-emerald-700">C. Capstone Evaluation and Research</h3>
                    <p class="mt-2">During the live testing period, certain information may be used to evaluate the system for the researchers' academic capstone project.</p>
                    <p class="mt-2">The evaluation may include assessment of:</p>
                    <ul class="mt-2 list-disc space-y-1 pl-5">
                        <li>System functionality;</li>
                        <li>Usability;</li>
                        <li>User acceptance;</li>
                        <li>Perceived usefulness;</li>
                        <li>User experience; and</li>
                        <li>Other system-related evaluation criteria defined in the approved research methodology.</li>
                    </ul>
                    <p class="mt-2">Where information is used for research, the researchers will seek to use <strong>aggregated, anonymized, or de-identified information whenever reasonably possible</strong> so that individual users are not unnecessarily identified.</p>
                    <p class="mt-2">Personal information will not be used for unrelated purposes without an appropriate lawful basis and, where required, additional notice or consent.</p>
                </section>

                <section>
                    <h2 class="text-xl font-bold text-emerald-800">4. Legal Basis for Processing</h2>
                    <div class="mt-3 space-y-3">
                        <p>The processing of personal information shall be based on an applicable lawful basis under the Data Privacy Act.</p>
                        <p>Where consent is relied upon, users will be provided with sufficient information regarding the nature, purpose, scope, duration, and other relevant details of the processing before consent is obtained.</p>
                        <p>For certain processing activities, another lawful basis recognized under the Data Privacy Act may apply, such as processing necessary for the provision of a requested service, compliance with a legal obligation, or another applicable basis.</p>
                        <p>Because the system may process health information, which is sensitive personal information, processing will be subject to the additional requirements applicable under the Data Privacy Act and other relevant laws and regulations.</p>
                    </div>
                </section>

                <section>
                    <h2 class="text-xl font-bold text-emerald-800">5. Consent</h2>
                    <div class="mt-3 space-y-3">
                        <p>Where consent is required as the legal basis for processing, consent must be:</p>
                        <ul class="list-disc space-y-1 pl-5">
                            <li>Freely given;</li>
                            <li>Specific;</li>
                            <li>Informed; and</li>
                            <li>Obtained before the applicable processing takes place.</li>
                        </ul>
                        <p>Users will be informed of the purpose and scope of processing at or before the time consent is obtained.</p>
                        <p>A user may withdraw consent where consent is the applicable basis for processing. Withdrawal of consent does not affect the lawfulness of processing that occurred before the withdrawal.</p>
                        <p>Withdrawal of consent may, however, affect the user's ability to continue using features that require the relevant personal information.</p>
                    </div>
                </section>

                <section>
                    <h2 class="text-xl font-bold text-emerald-800">6. Who May Access Your Information</h2>
                    <div class="mt-3 space-y-3">
                        <p>Access to personal information is restricted according to the user's role and the requirements of the telemedicine workflow.</p>
                        <p>Depending on the information involved, authorized access may be provided to:</p>
                        <ul class="list-disc space-y-1 pl-5">
                            <li>The patient or account holder;</li>
                            <li>Authorized CLSU Infirmary nurses;</li>
                            <li>Authorized CLSU Infirmary physicians;</li>
                            <li>Authorized system administrators, only to the extent necessary to perform legitimate administrative or technical functions;</li>
                            <li>The researchers, where access is necessary for approved system evaluation, maintenance, testing, or academic research purposes; and</li>
                            <li>Authorized service providers that process information on behalf of the system operator, where applicable and subject to appropriate safeguards and agreements.</li>
                        </ul>
                        <p>Healthcare and system personnel with access to confidential information are expected to maintain its confidentiality and process it only for authorized purposes.</p>
                        <p>Personal information will not be publicly displayed or disclosed to unauthorized individuals.</p>
                    </div>
                </section>

                <section>
                    <h2 class="text-xl font-bold text-emerald-800">7. Third-Party Services</h2>
                    <div class="mt-3 space-y-3">
                        <p>The system may use third-party technology or infrastructure providers to support specific technical functions, such as:</p>
                        <ul class="list-disc space-y-1 pl-5">
                            <li>Cloud hosting or server infrastructure;</li>
                            <li>Database or storage services;</li>
                            <li>Email delivery services;</li>
                            <li>Cloud-based file or media storage; and</li>
                            <li>Video or communication services used to facilitate online consultations.</li>
                        </ul>
                        <p>Where third-party providers process personal information on behalf of the system operator, appropriate measures should be taken to ensure that personal information is processed only for authorized purposes and is protected through reasonable and appropriate security safeguards.</p>
                        <p>Users should refer to the applicable privacy policies of third-party services where necessary.</p>
                    </div>
                </section>

                <section>
                    <h2 class="text-xl font-bold text-emerald-800">8. Data Sharing and Disclosure</h2>
                    <div class="mt-3 space-y-3">
                        <p>Personal information will not be sold or disclosed for commercial marketing purposes.</p>
                        <p>Information may be disclosed when reasonably necessary for:</p>
                        <ul class="list-disc space-y-1 pl-5">
                            <li>Providing the requested telemedicine service;</li>
                            <li>Authorized healthcare operations;</li>
                            <li>System administration and maintenance;</li>
                            <li>Academic system evaluation or research, subject to applicable safeguards;</li>
                            <li>Compliance with legal obligations;</li>
                            <li>Responding to lawful requests from government authorities; or</li>
                            <li>Protecting the rights, safety, security, or property of the users, the CLSU Infirmary, or other persons, when permitted by law.</li>
                        </ul>
                        <p>Only information reasonably necessary for the applicable purpose should be disclosed.</p>
                    </div>
                </section>

                <section>
                    <h2 class="text-xl font-bold text-emerald-800">9. Data Retention</h2>
                    <div class="mt-3 space-y-3">
                        <p>Personal information shall not be retained indefinitely.</p>
                        <p>Information collected through the system will be retained only for as long as reasonably necessary to fulfill the purposes for which it was collected, support legitimate operational or academic requirements, comply with applicable legal obligations, or establish, exercise, or defend legal claims, as applicable.</p>
                        <p>Because this system is currently being deployed for a limited <strong>five-day live testing period</strong>, the researchers will establish a specific retention and disposal schedule for information collected during the testing period.</p>
                        <p>Where information is no longer necessary and there is no legal or legitimate reason to retain it, it should be securely deleted, destroyed, anonymized, or otherwise disposed of in accordance with applicable requirements.</p>
                        <p>Aggregated or properly anonymized information that no longer identifies an individual may be retained for legitimate statistical or research purposes where appropriate safeguards are maintained.</p>
                    </div>
                </section>

                <section>
                    <h2 class="text-xl font-bold text-emerald-800">10. Security Measures</h2>
                    <div class="mt-3 space-y-3">
                        <p>Reasonable and appropriate organizational, physical, and technical measures shall be implemented to protect personal information against unauthorized access, alteration, disclosure, loss, destruction, or other unlawful processing.</p>
                        <p>Depending on the applicable system component, security measures may include:</p>
                        <ul class="list-disc space-y-1 pl-5">
                            <li>Role-based access controls;</li>
                            <li>User authentication;</li>
                            <li>Password protection;</li>
                            <li>Authorization checks;</li>
                            <li>Restricted access to consultation information;</li>
                            <li>Secure transmission of information;</li>
                            <li>Access controls for uploaded files;</li>
                            <li>Database security measures;</li>
                            <li>Monitoring of system activity;</li>
                            <li>Secure handling of authentication credentials;</li>
                            <li>Regular security testing and review; and</li>
                            <li>Procedures for responding to security incidents.</li>
                        </ul>
                        <p>Access to sensitive personal information should be limited to authorized persons whose functions require such access.</p>
                    </div>
                </section>

                <section>
                    <h2 class="text-xl font-bold text-emerald-800">11. Data Breach and Security Incidents</h2>
                    <div class="mt-3 space-y-3">
                        <p>The system will maintain procedures for identifying, documenting, investigating, and responding to security incidents and personal data breaches.</p>
                        <p>Where a personal data breach meets the requirements for mandatory notification under applicable law and regulations, the appropriate notifications shall be made to the affected data subjects and the National Privacy Commission within the prescribed period.</p>
                        <p>Under the implementing rules of the Data Privacy Act, a qualifying breach requiring notification generally involves sensitive personal information or information that may enable identity fraud where unauthorized acquisition is reasonably believed to create a real risk of serious harm. The applicable rules provide for notification within <strong>72 hours</strong> from knowledge of, or reasonable belief in, the occurrence of a qualifying breach.</p>
                    </div>
                </section>

                <section>
                    <h2 class="text-xl font-bold text-emerald-800">12. Your Rights as a Data Subject</h2>
                    <p class="mt-3">Subject to the limitations and conditions provided by law, you may have the following rights under the Data Privacy Act:</p>

                    <h3 class="mt-5 text-base font-semibold text-emerald-700">Right to Be Informed</h3>
                    <p class="mt-2">You have the right to know whether your personal information is being processed and to receive information about the nature, purpose, scope, and other relevant aspects of the processing.</p>

                    <h3 class="mt-5 text-base font-semibold text-emerald-700">Right to Access</h3>
                    <p class="mt-2">You may request reasonable access to your personal information being processed, subject to applicable legal limitations.</p>

                    <h3 class="mt-5 text-base font-semibold text-emerald-700">Right to Correct</h3>
                    <p class="mt-2">You may request correction of inaccurate or incomplete personal information.</p>

                    <h3 class="mt-5 text-base font-semibold text-emerald-700">Right to Object</h3>
                    <p class="mt-2">You may object to certain forms of processing when permitted by law.</p>

                    <h3 class="mt-5 text-base font-semibold text-emerald-700">Right to Withdraw Consent</h3>
                    <p class="mt-2">Where processing is based on consent, you may withdraw your consent, subject to applicable limitations and consequences.</p>

                    <h3 class="mt-5 text-base font-semibold text-emerald-700">Right to Erasure or Blocking</h3>
                    <p class="mt-2">You may request the deletion, destruction, or blocking of your personal information under circumstances provided by law.</p>

                    <h3 class="mt-5 text-base font-semibold text-emerald-700">Right to Data Portability</h3>
                    <p class="mt-2">Where applicable, you may request your personal data in a structured and commonly used format and have it transmitted to another personal information controller, subject to the conditions provided by law.</p>

                    <h3 class="mt-5 text-base font-semibold text-emerald-700">Right to File a Complaint</h3>
                    <p class="mt-2">You may file a complaint with the <strong>National Privacy Commission</strong> if you believe that your privacy rights have been violated or that your personal information has been unlawfully processed.</p>

                    <p class="mt-5">These rights are subject to limitations and conditions under the Data Privacy Act and other applicable laws.</p>
                </section>

                <section>
                    <h2 class="text-xl font-bold text-emerald-800">13. How to Exercise Your Rights</h2>
                    <div class="mt-3 space-y-3">
                        <p>To exercise your privacy rights or raise a concern regarding the processing of your personal information, you may contact:</p>

                        <div class="rounded-lg border border-emerald-100 bg-emerald-50 p-4">
                            <p class="font-semibold text-emerald-800">Privacy Contact:</p>
                            <p>Kim Josiah C. Gavino</p>
                            <p>Mark Jerrick M. Mana</p>

                            <p class="mt-3 font-semibold text-emerald-800">Email:</p>
                            <p><a href="mailto:kimjosiah.gavino@clsu2.edu.ph" class="text-emerald-700 underline hover:text-emerald-900">kimjosiah.gavino@clsu2.edu.ph</a></p>
                            <p><a href="mailto:markjerrick.mana@clsu2.edu.ph" class="text-emerald-700 underline hover:text-emerald-900">markjerrick.mana@clsu2.edu.ph</a></p>

                            <p class="mt-3 font-semibold text-emerald-800">Contact Number:</p>
                            <p><a href="tel:09126153860" class="text-emerald-700 underline hover:text-emerald-900">09126153860</a></p>
                        </div>

                        <p>When making a request, you may be asked to provide sufficient information to verify your identity and determine the nature of your request.</p>
                        <p>Requests will be handled in accordance with applicable data privacy requirements.</p>
                    </div>
                </section>

                <section>
                    <h2 class="text-xl font-bold text-emerald-800">14. Capstone Testing Notice</h2>
                    <div class="mt-3 space-y-3">
                        <p>This system is currently being used as part of an academic capstone project.</p>
                        <p>The five-day live testing period is intended to evaluate the system under actual operating conditions of the CLSU Infirmary.</p>
                        <p>The system is:</p>
                        <ul class="list-disc space-y-1 pl-5">
                            <li>A capstone project under development;</li>
                            <li>Being evaluated for functionality, usability, and user acceptance;</li>
                            <li>Not yet represented as an officially integrated CLSU production system; and</li>
                            <li>Intended to be further developed toward potential future integration with CLSU information systems.</li>
                        </ul>
                        <p>Participation in system evaluation does not authorize the researchers to use personal information for purposes unrelated to the purposes disclosed in this Privacy Policy.</p>
                        <p>Where possible, research results will be reported using aggregated or de-identified information rather than individually identifying users.</p>
                    </div>
                </section>

                <section>
                    <h2 class="text-xl font-bold text-emerald-800">15. Changes to This Privacy Policy</h2>
                    <div class="mt-3 space-y-3">
                        <p>This Privacy Policy may be updated when necessary to reflect changes in the system, applicable laws and regulations, data processing activities, or security practices.</p>
                        <p>Material changes should be communicated through an appropriate notice before or when they take effect, where required.</p>
                        <p>The latest version of this Privacy Policy will be made available through the system.</p>
                    </div>
                </section>

                <section>
                    <h2 class="text-xl font-bold text-emerald-800">16. Contact and Complaints</h2>
                    <div class="mt-3 space-y-3">
                        <p>For questions, concerns, or requests regarding the processing of your personal information, please contact the designated privacy contact identified above.</p>
                        <p>You may also contact the <strong>National Privacy Commission of the Philippines</strong> regarding concerns involving your rights under the Data Privacy Act.</p>
                    </div>
                </section>

                <section class="rounded-lg border border-amber-200 bg-amber-50 p-4">
                    <p class="font-medium text-amber-900">By using this system, you acknowledge that you have been informed of the processing of your personal information as described in this Privacy Policy. Where consent is required as the applicable legal basis, you will be asked to provide the appropriate consent before the relevant processing takes place.</p>
                </section>

            </div>

            <div class="mt-10">
                <a href="{{ url('/') }}" class="text-sm font-medium text-emerald-700 hover:text-emerald-900">← Back to Home</a>
            </div>
        </main>
    </body>
</html>
