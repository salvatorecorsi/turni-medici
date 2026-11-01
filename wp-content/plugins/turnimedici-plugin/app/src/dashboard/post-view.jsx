import { useState, useEffect } from '@wordpress/element';
import { getInfoPost } from './api';

export default function PostView({ id, onBack }) {
    const [post, setPost] = useState(null);
    const [error, setError] = useState(null);

    useEffect(() => {
        setPost(null);
        setError(null);
        getInfoPost(id).then(setPost).catch((err) => setError(err.message));
    }, [id]);

    return (
        <div className="tmv tmd-info">
            <div className="tmv-topline">
                <button type="button" className="tmv-back tmd-linkbtn" onClick={onBack}>‹ Dashboard</button>
            </div>
            {error ? (
                <div className="tmd-error">{error}</div>
            ) : !post ? (
                <div className="tmd-loading">Caricamento…</div>
            ) : (
                <>
                    <div className="tmd-info__head">
                        <span className="tmd-info__eyebrow">{post.type_label} · {post.date}</span>
                        <h1 className="tmd-info__title">{post.title}</h1>
                    </div>
                    <article className="tmd-rich tm-article" dangerouslySetInnerHTML={{ __html: post.content }} />
                </>
            )}
        </div>
    );
}
