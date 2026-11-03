<x-layouts::legal
    :title="__('Privacy Policy')"
    updated="October 28, 2026"
    :summary="__('The Faculty Research Portal keeps the records ISU Cauayan needs to track faculty research, from concept proposal to terminal report. This page explains what it keeps, why, who can see it, and how to reach the Research Office about it.')"
>
    <h2 id="who-we-are">{{ __('Who runs the portal') }}</h2>
    <p>{{ __('The Research Office of Isabela State University – Cauayan Campus runs the Faculty Research Portal and is responsible for the information in it, under the Data Privacy Act of 2012 (Republic Act No. 10173).') }}</p>

    <h2 id="what-we-keep">{{ __('What the portal keeps') }}</h2>
    <ul>
        <li><strong>{{ __('Your account:') }}</strong> {{ __('your name, ISU email address, college, researcher ID, and your password, which is stored scrambled so no one can read it. Your mobile number and profile photo are kept only if you add them.') }}</li>
        <li><strong>{{ __('Research records:') }}</strong> {{ __('project titles, abstracts, proponents, dates, the Research Office’s review decisions and remarks, publications, and the documents you upload for each stage.') }}</li>
        <li><strong>{{ __('Proponents without an account:') }}</strong> {{ __('only the full name a co-proponent types on the project. Staff and people outside the campus are not given an account, and the portal never emails them.') }}</li>
        <li><strong>{{ __('Activity:') }}</strong> {{ __('a log of sign-ins and of changes made in the portal, with the time and the person who made them.') }}</li>
        <li><strong>{{ __('Sign-in cookie:') }}</strong> {{ __('one cookie that keeps you signed in. The portal uses no advertising or tracking cookies and no analytics.') }}</li>
    </ul>

    <h2 id="why">{{ __('Why it keeps them') }}</h2>
    <p>{{ __('Only to run faculty research at ISU Cauayan: to let you file and update your projects, let the Research Office review concept proposals, send reminders about target dates, and keep a history of what changed. The portal does not use your information for anything else, and never sells it.') }}</p>

    <h2 id="who-sees-it">{{ __('Who can see it') }}</h2>
    <ul>
        <li>{{ __('You, for your own account and projects.') }}</li>
        <li>{{ __('The other proponents listed on a project, for that project.') }}</li>
        <li>{{ __('Research Office administrators, for every project and account, so they can review and manage research.') }}</li>
    </ul>
    <p>{{ __('No one outside ISU sees your information, except the services that host the portal for the Research Office, listed below, and only as far as hosting needs.') }}</p>

    <h2 id="where-it-is-stored">{{ __('Where it is stored') }}</h2>
    <ul>
        <li><strong>{{ __('Laravel Cloud') }}</strong> {{ __('hosts the portal, its database, and profile photos, and backs up the database daily.') }}</li>
        <li><strong>{{ __('Google Drive') }}</strong> {{ __('holds uploaded project documents, in a private folder of the Research Office’s Google account. The folder is shared with no one. Documents open only through the portal, after it checks that you may see them.') }}</li>
        <li><strong>{{ __('Gmail') }}</strong> {{ __('sends the portal’s emails, such as verification links, password resets, and review notices, from the Research Office’s account.') }}</li>
    </ul>

    <h2 id="google">{{ __('How the portal uses Google') }}</h2>
    <p>{{ __('You never sign in to the portal with Google. Only the Research Office connects its own Google account, once, so the portal can store documents. The portal asks Google for access to the files it creates itself and nothing else in that account. It does not read, change, or share any other file, and it does not use Google data for advertising or pass it to anyone. The portal’s use of information received from Google APIs follows the Google API Services User Data Policy, including the Limited Use requirements.') }}</p>

    <h2 id="how-long">{{ __('How long it is kept') }}</h2>
    <ul>
        <li>{{ __('Research records and documents are kept for as long as the university needs them as research records. A new upload for a stage replaces the earlier file.') }}</li>
        <li>{{ __('Activity log entries are deleted after 12 months, except a project’s own history, which stays with the project.') }}</li>
        <li>{{ __('A sign-up the Research Office does not approve stays on record, so the same email cannot sign up again. Ask the Research Office to remove it.') }}</li>
    </ul>

    <h2 id="your-rights">{{ __('Your rights') }}</h2>
    <p>{{ __('Under the Data Privacy Act you may ask to see the personal information the portal holds about you, ask for mistakes to be corrected, object to its use, or ask for it to be removed where the university no longer needs it as a research record. You can update your name, mobile number, and photo yourself under My Profile. For anything else, write to the Research Office.') }}</p>

    <h2 id="contact">{{ __('Contact') }}</h2>
    <p>
        {{ __('Research Office, Isabela State University – Cauayan Campus:') }}
        <a href="mailto:isucyn.facultyresearchportal@gmail.com">isucyn.facultyresearchportal@gmail.com</a>
    </p>
    <p>{{ __('If this policy changes, the new version is posted here with a new date. See also the') }} <a href="{{ route('terms') }}">{{ __('Terms of Service') }}</a>.</p>
</x-layouts::legal>
