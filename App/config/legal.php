<?php

/**
 * legal.php — the Terms of Service and Privacy Policy shown by the reader in
 * App/views/includes/legal_modal.php.
 *
 * ── HOW TO FILL THIS IN ──────────────────────────────────────────────────
 * Paste the real text into 'sections' below. Each entry is
 * ['heading' => '...', 'body' => ['paragraph', 'paragraph', ...]].
 * Plain text only — it is escaped on output, so nothing here can inject
 * markup into the page. Set 'effective' to the date the document takes
 * effect, then the reader stops showing its "not published yet" notice on
 * its own; no code change is needed.
 *
 * Until then 'sections' is deliberately left empty. Placeholder legalese
 * would read as though the school had agreed to terms nobody wrote, which
 * is worse than plainly saying the document is still being prepared.
 */

return [
    'terms' => [
        'title' => 'Terms of Service',
        'effective' => '2026-09-30',

        'sections' => [
            [
                'heading' => '1. Acceptance of Terms',
                'body' => [
                    'By accessing and using the Peer Mentoring System, you agree to comply with these Terms and Conditions.'
                ],
            ],

            [
                'heading' => '2. Authorized Users',
                'body' => [
                    'This system is exclusively for students of the College of Education of Nueva Ecija University of Science and Technology (NEUST–COEd).'
                ],
            ],

            [
                'heading' => '3. User Roles',
                'body' => [
                    'Mentee – Requests mentoring sessions.',
                    'Mentor – Provides academic guidance and mentoring.'
                ],
            ],

            [
                'heading' => '4. Student Verification (COR Requirement)',
                'body' => [
                    'To ensure that only legitimate students can access the system:',
                    'Users are required to upload a Certificate of Registration (COR).',
                    'The COR will be used for verification purposes only.'
                ],
            ],

            [
                'heading' => '5. Optional COR Masking',
                'body' => [
                    'Users are allowed but not required to mask or hide sensitive information in their COR before uploading.',
                    'Masking is optional but strongly recommended.',
                    'The system only requires visibility of:',
                    '• Name',
                    '• Year Level',
                    '• Program/Major'
                ],
            ],

            [
                'heading' => '6. User Responsibility for Uploaded COR',
                'body' => [
                    'Users are responsible for:',
                    '• Ensuring the authenticity of the COR.',
                    '• Deciding whether to mask sensitive information.',
                    '• Understanding the risks associated with sharing unmasked data.'
                ],
            ],

            [
                'heading' => '7. Account Responsibility',
                'body' => [
                    'Users must maintain the confidentiality of their accounts and are responsible for all activities performed under their accounts.'
                ],
            ],

            [
                'heading' => '8. Proper Use',
                'body' => [
                    'Users must NOT:',
                    '• Submit fake, fraudulent, or altered COR documents.',
                    '• Misuse the system.',
                    '• Engage in harassment, abuse, or misconduct.'
                ],
            ],

            [
                'heading' => '9. Session Conduct',
                'body' => [
                    '• Academic and respectful interactions are required.',
                    '• Mentors must act professionally.',
                    '• Mentees must follow agreed schedules and communicate appropriately.'
                ],
            ],

            [
                'heading' => '10. Cancellation Policy',
                'body' => [
                    '• Sessions may be canceled before the scheduled time.',
                    '• Excessive cancellations may result in account restrictions or other administrative action.'
                ],
            ],

            [
                'heading' => '11. Account Suspension',
                'body' => [
                    'Administrators may suspend or restrict accounts for:',
                    '• Policy violations.',
                    '• Submission of fraudulent documents.',
                    '• System abuse or unauthorized activities.'
                ],
            ],

            [
                'heading' => '12. Limitation of Liability',
                'body' => [
                    'The system provides academic mentoring assistance but does not guarantee specific academic, educational, or other outcomes.'
                ],
            ],
        ],
    ],

    'terms_of_use' => [
        'title' => 'Terms of Use',
        'effective' => '2026-09-30',

        'sections' => [
            [
                'heading' => '1. Purpose',
                'body' => [
                    'The Peer Mentoring System provides a platform for academic mentoring among students of NEUST–COEd.'
                ],
            ],

            [
                'heading' => '2. User Responsibilities',
                'body' => [
                    'Users must:',
                    '• Provide accurate and truthful information.',
                    '• Upload a valid Certificate of Registration (COR).',
                    '• Respect other users and system administrators.'
                ],
            ],

            [
                'heading' => '3. COR Upload Guidelines',
                'body' => [
                    'Users acknowledge that:',
                    '• COR upload is required for student verification.',
                    '• Masking sensitive information is optional but encouraged.',
                    '• The system only requires visibility of:',
                    '• Name',
                    '• Year Level',
                    '• Program/Major'
                ],
            ],

            [
                'heading' => '4. Mentor Responsibilities',
                'body' => [
                    'Mentors must:',
                    '• Provide accurate and appropriate academic guidance.',
                    '• Maintain professionalism during mentoring sessions.',
                    '• Respect the confidentiality and privacy of mentees.'
                ],
            ],

            [
                'heading' => '5. Mentee Responsibilities',
                'body' => [
                    'Mentees must:',
                    '• Attend scheduled mentoring sessions.',
                    '• Be prepared for mentoring sessions.',
                    '• Communicate respectfully with mentors.'
                ],
            ],

            [
                'heading' => '6. Prohibited Activities',
                'body' => [
                    'The following activities are prohibited:',
                    '• Academic dishonesty.',
                    '• Uploading fake or fraudulent documents.',
                    '• Unauthorized access to the system.',
                    '• Abuse or misuse of system functionality.'
                ],
            ],

            [
                'heading' => '7. Intellectual Property',
                'body' => [
                    'System materials, content, designs, and other resources are owned by or used with authorization by the developers and NEUST, subject to applicable policies and intellectual property rights.'
                ],
            ],
        ],
    ],

    'privacy' => [
        'title' => 'Privacy Policy',
        'effective' => '2026-09-30',

        'sections' => [
            [
                'heading' => '1. Information Collected',
                'body' => [
                    'The system may collect:',
                    '• Name',
                    '• Email address',
                    '• Program and Year Level',
                    '• Profile information',
                    '• Uploaded Certificate of Registration (COR)'
                ],
            ],

            [
                'heading' => '2. Purpose of Data Collection',
                'body' => [
                    'Collected information may be used for:',
                    '• User and student verification.',
                    '• Account management.',
                    '• Mentoring session management.',
                    '• System administration and improvement.'
                ],
            ],

            [
                'heading' => '3. COR Data Handling',
                'body' => [
                    'COR documents are collected for student verification purposes.',
                    'Access to uploaded COR documents is restricted to authorized administrators.',
                    'The system does not require users to expose unnecessary COR information.'
                ],
            ],

            [
                'heading' => '4. Optional Data Masking',
                'body' => [
                    'Users may choose to mask or hide sensitive information before uploading their COR.',
                    'Masking is not mandatory but is strongly encouraged when the information is not required for verification.',
                    'Users retain control over information they choose to display, subject to the requirements of the verification process.'
                ],
            ],

            [
                'heading' => '5. Data Minimization',
                'body' => [
                    'The system only requires the following information from the COR for verification:',
                    '• Name',
                    '• Year Level',
                    '• Program/Major',
                    'Any additional visible information is not required for the verification process.'
                ],
            ],

            [
                'heading' => '6. Data Protection',
                'body' => [
                    'The system applies appropriate security measures, including:',
                    '• Secure authentication.',
                    '• Role-based access control.',
                    '• Restricted administrative access.',
                    '• Confidential handling of user information.'
                ],
            ],

            [
                'heading' => '7. Data Sharing',
                'body' => [
                    'User information will not be shared with third parties except when authorized by the user, required for legitimate institutional purposes, or otherwise permitted or required by applicable law and regulations.'
                ],
            ],

            [
                'heading' => '8. Data Retention',
                'body' => [
                    'COR files may be deleted after verification when they are no longer required, or securely retained when continued storage is necessary for legitimate administrative, institutional, or legal purposes.'
                ],
            ],

            [
                'heading' => '9. User Rights',
                'body' => [
                    'Subject to applicable laws, regulations, and institutional policies, users may:',
                    '• Request access to their personal data.',
                    '• Request correction of inaccurate information.',
                    '• Request deletion of personal data when applicable.',
                    '• Raise concerns regarding the handling of their personal information.'
                ],
            ],
        ],
    ],

    'neust_standards' => [
        'title' => 'NEUST-Aligned Standards',
        'effective' => '2026-09-30',

        'sections' => [
            [
                'heading' => '1. Academic Integrity',
                'body' => [
                    'The system promotes honest learning and discourages misuse of the mentoring platform.'
                ],
            ],

            [
                'heading' => '2. Data Privacy Compliance',
                'body' => [
                    'The system is designed with consideration for the principles of the Data Privacy Act of 2012 and applicable institutional policies.',
                    'Key principles include:',
                    '• Consent.',
                    '• Transparency.',
                    '• Data minimization.',
                    '• Appropriate protection of personal information.',
                    '• User rights and control over personal information.'
                ],
            ],

            [
                'heading' => '3. Ethical Standards',
                'body' => [
                    'The system promotes:',
                    '• Respectful communication.',
                    '• A harassment-free environment.',
                    '• Professional mentoring.',
                    '• Appropriate conduct between mentors and mentees.'
                ],
            ],

            [
                'heading' => '4. Security Standards',
                'body' => [
                    'The system incorporates security practices such as:',
                    '• Role-based access.',
                    '• Administrator verification.',
                    '• Protected user information.',
                    '• Controlled access to uploaded documents.'
                ],
            ],

            [
                'heading' => '5. System Quality Standards',
                'body' => [
                    'The system considers the following quality areas:',
                    '• Usability (SUS).',
                    '• Functionality.',
                    '• Reliability.',
                    '• Efficiency.'
                ],
            ],
        ],
    ],
];