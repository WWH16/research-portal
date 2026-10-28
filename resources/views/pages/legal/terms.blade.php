<x-layouts::legal
    :title="__('Terms of Service')"
    updated="October 28, 2026"
    :summary="__('These terms are the rules for using the Faculty Research Portal of Isabela State University - Cauayan Campus. By signing up or signing in, you agree to them.')"
>
    <h2 id="who-may-use">{{ __('Who may use the portal') }}</h2>
    <p>{{ __('The portal is for ISU Cauayan faculty and staff. You sign up with your own ISU email address, ending in @isu.edu.ph, and confirm it from your inbox. The Research Office then approves or turns down each sign-up. You can use the portal only after it is approved.') }}</p>

    <h2 id="your-account">{{ __('Your account') }}</h2>
    <ul>
        <li>{{ __('One account per person. Do not share your password or let anyone else sign in as you.') }}</li>
        <li>{{ __('Keep your name, college, and contact details accurate.') }}</li>
        <li>{{ __('Tell the Research Office at once if you think someone else has used your account.') }}</li>
    </ul>

    <h2 id="what-you-upload">{{ __('What you upload') }}</h2>
    <ul>
        <li>{{ __('Upload only documents for projects you are a proponent of, as PDF, DOC, or DOCX files of up to 25 MB.') }}</li>
        <li>{{ __('Upload only work you have the right to share with the Research Office, and include other people’s personal information only as far as the research needs it.') }}</li>
        <li>{{ __('A new upload for a stage replaces the earlier file for that stage. Keep your own copies of your documents.') }}</li>
    </ul>

    <h2 id="research-office">{{ __('What the Research Office does') }}</h2>
    <p>{{ __('The Research Office reviews concept proposals and passes them or returns them for revision with remarks. It approves, turns down, edits, and removes accounts, and it can see every project in the portal. It does not change the details or documents of your projects for you.') }}</p>

    <h2 id="not-allowed">{{ __('What is not allowed') }}</h2>
    <ul>
        <li>{{ __('Trying to see projects, documents, or accounts that are not yours.') }}</li>
        <li>{{ __('Uploading harmful files, or anything unrelated to your research.') }}</li>
        <li>{{ __('Interfering with how the portal works, or with other people’s use of it.') }}</li>
    </ul>
    <p>{{ __('The Research Office may suspend or remove an account that breaks these rules, and may refer the matter under university rules.') }}</p>

    <h2 id="availability">{{ __('Availability') }}</h2>
    <p>{{ __('The Research Office keeps the portal running as well as it can, but cannot promise it is always available. Document storage can be unavailable for a while; when it is, the portal tells you and nothing you already saved is lost.') }}</p>

    <h2 id="privacy">{{ __('Your information') }}</h2>
    <p>{{ __('How the portal handles your personal information is explained in the') }} <a href="{{ route('privacy') }}">{{ __('Privacy Policy') }}</a>.</p>

    <h2 id="changes">{{ __('Changes and contact') }}</h2>
    <p>{{ __('If these terms change, the new version is posted here with a new date. Questions go to the Research Office:') }} <a href="mailto:isucyn.facultyresearchportal@gmail.com">isucyn.facultyresearchportal@gmail.com</a></p>
</x-layouts::legal>
